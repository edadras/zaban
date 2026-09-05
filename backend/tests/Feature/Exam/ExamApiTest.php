<?php

namespace Tests\Feature\Exam;

use App\AI\AiOrchestrator;
use App\Services\Exam\ExamService;
use Laravel\Sanctum\Sanctum;

/** The HTTP surface end to end: profiles, a sitting, results and progress. */
class ExamApiTest extends ExamTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->registerRoutes();
        $this->mock(AiOrchestrator::class, fn ($m) => $m->shouldNotReceive('text'));
    }

    public function test_it_lists_the_four_exam_profiles_with_their_cefr_mapping(): void
    {
        Sanctum::actingAs($this->learner());

        $response = $this->getJson('/api/v1/exams/types')->assertOk();
        $codes = collect($response->json('data'))->pluck('code')->all();

        $this->assertEqualsCanonicalizing(
            ['ielts_academic', 'toefl_ibt', 'cambridge_b2', 'pte_academic'],
            $codes,
        );

        $ielts = collect($response->json('data'))->firstWhere('code', 'ielts_academic');
        $this->assertSame('band', $ielts['score']['type']);
        $this->assertSame(0.5, $ielts['score']['step']);
        $this->assertCount(4, $ielts['sections']);
        $this->assertNotEmpty($ielts['cefr_mapping']);
    }

    public function test_it_exposes_one_profile_with_section_timings_and_question_types(): void
    {
        Sanctum::actingAs($this->learner());
        $ielts = $this->examType('ielts_academic');

        $response = $this->getJson("/api/v1/exams/types/{$ielts->id}")->assertOk();

        $sections = collect($response->json('data.sections'))->keyBy('code');
        $this->assertSame(40, $sections['listening']['duration_minutes']);
        $this->assertSame(60, $sections['reading']['duration_minutes']);
        $this->assertSame('rubric', $sections['writing']['scoring']['mode']);
        $this->assertCount(4, $sections['writing']['scoring']['criteria']);
        $this->assertCount(3, $sections['speaking']['scoring']['parts']);
        $this->assertNotEmpty($sections['reading']['task_types']);
    }

    public function test_a_full_practice_run_from_start_to_results(): void
    {
        $user = $this->learner();
        Sanctum::actingAs($user);

        $section = $this->section('ielts_academic', 'reading');
        $task = $this->objectiveTask($section, 'true_false_not_given', [
            ['stem' => 'The author supports the policy.', 'options' => ['True', 'False', 'Not Given'], 'correct' => 0],
            ['stem' => 'The study ran for a decade.', 'options' => ['True', 'False', 'Not Given'], 'correct' => 2],
        ]);

        $started = $this->postJson('/api/v1/exams/attempts', [
            'exam_type_id' => $this->examType('ielts_academic')->id,
            'mode' => ExamService::MODE_SECTION,
            'exam_section_id' => $section->id,
        ])->assertCreated();

        $attemptId = $started->json('data.id');
        $this->assertTrue($started->json('data.estimate.is_estimate'));
        $this->assertFalse($started->json('data.estimate.is_official'));

        $next = $this->getJson("/api/v1/exams/attempts/{$attemptId}/next-task")->assertOk();
        $this->assertSame($task->id, $next->json('data.task.id'));
        $this->assertSame(3600, $next->json('data.timing.section_allowed_seconds'));
        $this->assertCount(2, $next->json('data.exercises'));
        // The answer key must never leave the server.
        $this->assertArrayNotHasKey('is_correct', $next->json('data.exercises.0.options.0'));

        $exercises = app(ExamService::class)->taskExercises($task)->values();
        $answers = [
            $exercises[0]->id => ['selected' => $exercises[0]->options->firstWhere('is_correct', true)->id],
            $exercises[1]->id => ['selected' => $exercises[1]->options->firstWhere('is_correct', false)->id],
        ];

        $this->postJson("/api/v1/exams/attempts/{$attemptId}/tasks/{$task->id}/response", [
            'answers' => $answers,
            'seconds_used' => 300,
        ])->assertOk()
            ->assertJsonPath('data.raw_score', 1)
            ->assertJsonPath('data.scored', true);

        $this->getJson("/api/v1/exams/attempts/{$attemptId}/next-task")
            ->assertOk()
            ->assertJsonPath('data.complete', true);

        $finished = $this->postJson("/api/v1/exams/attempts/{$attemptId}/finish")->assertOk();

        $this->assertSame('completed', $finished->json('data.attempt.status'));
        $reading = collect($finished->json('data.skills'))->firstWhere('section', 'reading');
        $this->assertSame(2, $reading['coverage']['items_marked']);
        $this->assertSame(40, $reading['coverage']['items_in_full_paper']);
        $this->assertTrue($reading['coverage']['extrapolated']);
        $this->assertNotEmpty($finished->json('data.time_management.sections'));
        $this->assertSame('true_false_not_given', $finished->json('data.question_types.0.task_type'));
        $this->assertStringContainsString('not an official result', $finished->json('data.estimate.disclaimer'));

        $this->getJson("/api/v1/exams/attempts/{$attemptId}/results")
            ->assertOk()
            ->assertJsonPath('data.attempt.id', $attemptId);
    }

    public function test_another_learners_attempt_is_forbidden(): void
    {
        $owner = $this->learner();
        $section = $this->section('ielts_academic', 'listening');
        $attempt = app(ExamService::class)->start(
            $owner->id, $this->examType('ielts_academic'), ExamService::MODE_SECTION, $section->id,
        );

        Sanctum::actingAs($this->learner());
        $this->getJson("/api/v1/exams/attempts/{$attempt->id}")->assertForbidden();
    }

    public function test_starting_a_section_rehearsal_without_a_section_is_rejected(): void
    {
        Sanctum::actingAs($this->learner());

        $this->postJson('/api/v1/exams/attempts', [
            'exam_type_id' => $this->examType('ielts_academic')->id,
            'mode' => ExamService::MODE_SECTION,
        ])->assertStatus(422)->assertJsonValidationErrors('exam_section_id');
    }

    public function test_a_section_from_a_different_exam_is_refused_with_a_stable_error_code(): void
    {
        Sanctum::actingAs($this->learner());

        $this->postJson('/api/v1/exams/attempts', [
            'exam_type_id' => $this->examType('ielts_academic')->id,
            'mode' => ExamService::MODE_SECTION,
            'exam_section_id' => $this->section('toefl_ibt', 'reading')->id,
        ])->assertStatus(422)->assertJsonPath('error.code', 'exam_section_mismatch');
    }

    public function test_progress_returns_a_series_the_client_can_plot(): void
    {
        $user = $this->learner();
        Sanctum::actingAs($user);

        $section = $this->section('ielts_academic', 'listening');
        $task = $this->objectiveTask($section, 'multiple_choice', [
            ['stem' => 'Q1', 'options' => ['a', 'b'], 'correct' => 0],
        ]);

        $exams = app(ExamService::class);
        $attempt = $exams->start($user->id, $this->examType('ielts_academic'), ExamService::MODE_SECTION, $section->id);
        $exams->nextTask($attempt);
        $exercise = $exams->taskExercises($task)->first();
        $exams->submitResponse($attempt->fresh('sectionAttempts.section'), $task, [
            'answers' => [$exercise->id => ['selected' => $exercise->options->firstWhere('is_correct', true)->id]],
        ]);
        $exams->finish($attempt->fresh('sectionAttempts.section'), app(\App\Services\Exam\ScoringService::class));

        $response = $this->getJson('/api/v1/exams/progress')->assertOk();

        $this->assertSame(1, $response->json('data.attempts'));
        $this->assertSame('ielts_academic', $response->json('data.points.0.exam_type'));
        $this->assertEquals(9.0, $response->json('data.points.0.skills.listening'));
        $this->assertTrue($response->json('data.estimate.is_estimate'));
    }

    public function test_the_endpoints_require_authentication(): void
    {
        $this->getJson('/api/v1/exams/types')->assertUnauthorized();
        $this->getJson('/api/v1/exams/progress')->assertUnauthorized();
    }
}
