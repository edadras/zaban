<?php

namespace Tests\Feature\Exam;

use App\AI\AiOrchestrator;
use App\AI\Support\TextRequest;
use App\AI\Support\TextResult;
use App\Http\Resources\Exam\ExamAttemptResource;
use App\Models\ExamScore;
use App\Models\LearnerError;
use App\Models\LearnerSkillState;
use App\Models\Skill;
use App\Services\Exam\ExamEstimate;
use App\Services\Exam\ExamService;
use App\Services\Exam\ScoringService;
use Illuminate\Http\Request;
use Mockery;

/**
 * Two things must hold for every AI-produced number: the request that produced
 * it went out in the shape the rubric demands, and the result is labelled an
 * estimate everywhere it surfaces. A missing model must produce no score at all.
 */
class AiEstimateTest extends ExamTestCase
{
    /** @var TextRequest[] */
    private array $requests = [];

    private function fakeExaminer(array $byTask): void
    {
        $this->requests = [];

        $this->mock(AiOrchestrator::class, function ($mock) use ($byTask) {
            $mock->shouldReceive('text')
                ->andReturnUsing(function (TextRequest $request) use ($byTask) {
                    $this->requests[] = $request;
                    $taskType = $request->metadata['exam_task_type'] ?? null;
                    $scores = $byTask[$taskType] ?? 6.0;

                    return new TextResult(ok: true, json: [
                        'criteria' => array_map(fn (string $code) => [
                            'code' => $code,
                            'score' => $scores,
                            'rationale' => "Rationale for {$code}.",
                            'evidence' => ['a quoted phrase'],
                        ], [
                            'task_achievement', 'coherence_and_cohesion',
                            'lexical_resource', 'grammatical_range_and_accuracy',
                        ]),
                        'errors' => [
                            ['error_type' => 'article', 'subtype' => 'missing_definite',
                             'input' => 'in beginning', 'expected' => 'in the beginning', 'severity' => 2],
                        ],
                        'summary' => 'Overall a competent response.',
                    ]);
                });
        });
    }

    private function writeBothTasks(int $userId): array
    {
        $section = $this->section('ielts_academic', 'writing');
        $this->task($section, 'task_1_report', 0);
        $this->task($section, 'task_2_essay', 1);

        $exams = app(ExamService::class);
        $attempt = $exams->start($userId, $this->examType('ielts_academic'), ExamService::MODE_SECTION, $section->id);

        foreach (['first', 'second'] as $n) {
            $next = $exams->nextTask($attempt->fresh('sectionAttempts.section'));
            $exams->submitResponse(
                $attempt->fresh('sectionAttempts.section'),
                $next['task'],
                ['text' => "This is the {$n} written response, long enough to be marked properly."],
                900,
            );
        }

        return [$exams, $attempt, $section];
    }

    public function test_the_rubric_request_carries_the_seeded_criteria_and_is_never_cached(): void
    {
        $this->fakeExaminer(['task_1_report' => 6.0, 'task_2_essay' => 7.5]);

        $user = $this->learner();
        [$exams, $attempt] = $this->writeBothTasks($user->id);
        $exams->finish($attempt->fresh('sectionAttempts.section'), app(ScoringService::class));

        $this->assertCount(2, $this->requests, 'one marking call per written task');

        foreach ($this->requests as $request) {
            $this->assertSame(ScoringService::FEATURE_RUBRIC, $request->feature);
            $this->assertSame($user->id, $request->userId);
            // A learner's own writing must never be served from a shared cache.
            $this->assertFalse($request->cacheable);
            $this->assertLessThanOrEqual(0.3, $request->temperature, 'marking should be near-deterministic');

            $schema = $request->schema;
            $this->assertIsArray($schema);
            $criterionSchema = $schema['properties']['criteria']['items'];
            $this->assertSame([
                'task_achievement', 'coherence_and_cohesion',
                'lexical_resource', 'grammatical_range_and_accuracy',
            ], $criterionSchema['properties']['code']['enum']);
            $this->assertSame(0.0, $criterionSchema['properties']['score']['minimum']);
            $this->assertSame(9.0, $criterionSchema['properties']['score']['maximum']);
            $this->assertSame(4, $schema['properties']['criteria']['minItems']);
            $this->assertContains('error_type', $schema['properties']['errors']['items']['required']);

            $this->assertStringContainsString('IELTS Academic', $request->system);
            $this->assertStringContainsString('written response', $request->prompt);
        }
    }

    public function test_criterion_scores_are_stored_weighted_and_flagged_as_estimates(): void
    {
        $this->fakeExaminer(['task_1_report' => 6.0, 'task_2_essay' => 7.5]);

        $user = $this->learner();
        [$exams, $attempt, $section] = $this->writeBothTasks($user->id);
        $finished = $exams->finish($attempt->fresh('sectionAttempts.section'), app(ScoringService::class));

        $criteria = ExamScore::where('exam_attempt_id', $finished->id)
            ->get()
            ->reject(fn (ExamScore $s) => ExamService::isResponseCriterion($s->criterion));

        $this->assertCount(4, $criteria);
        foreach ($criteria as $score) {
            // IELTS Task 2 counts double: (6.0 + 2 x 7.5) / 3 = 7.0.
            $this->assertSame(7.0, (float) $score->score);
            $this->assertTrue($score->evidence['is_ai_estimated']);
            $this->assertSame(ExamEstimate::AI_DISCLAIMER, $score->evidence['disclaimer']);
        }

        $sectionAttempt = $finished->sectionAttempts->firstWhere('exam_section_id', $section->id);
        $this->assertSame(7.0, (float) $sectionAttempt->estimated_score);
        $this->assertTrue((bool) $finished->is_ai_estimated);
    }

    public function test_the_api_resource_always_carries_the_estimate_disclaimer(): void
    {
        $this->fakeExaminer(['task_1_report' => 6.0, 'task_2_essay' => 7.5]);

        $user = $this->learner();
        [$exams, $attempt] = $this->writeBothTasks($user->id);
        $finished = $exams->finish($attempt->fresh('sectionAttempts.section'), app(ScoringService::class));

        $payload = (new ExamAttemptResource($finished->load(['examType', 'sectionAttempts.section'])))
            ->toArray(Request::create('/'));

        $this->assertTrue($payload['estimate']['is_estimate']);
        $this->assertFalse($payload['estimate']['is_official']);
        $this->assertTrue($payload['estimate']['is_ai_estimated']);
        $this->assertStringContainsString('not an official result', $payload['estimate']['disclaimer']);
        $this->assertStringContainsString('cannot be used', $payload['estimate']['disclaimer']);
    }

    public function test_errors_the_examiner_reports_reach_the_shared_error_log(): void
    {
        $this->fakeExaminer(['task_1_report' => 6.0, 'task_2_essay' => 6.0]);

        $user = $this->learner();
        [$exams, $attempt] = $this->writeBothTasks($user->id);
        $exams->finish($attempt->fresh('sectionAttempts.section'), app(ScoringService::class));

        $error = LearnerError::where('user_id', $user->id)->where('error_type', 'article')->firstOrFail();
        $this->assertSame('missing_definite', $error->error_subtype);
        $this->assertSame('in the beginning', $error->expected);
        // An AI-spotted error is a hint, not a certainty.
        $this->assertLessThan(1.0, (float) $error->confidence);
    }

    public function test_a_failed_model_call_leaves_the_section_unscored_rather_than_inventing_a_band(): void
    {
        $this->mock(AiOrchestrator::class, function ($mock) {
            $mock->shouldReceive('text')->andReturn(TextResult::failure('provider unavailable'));
        });

        $user = $this->learner();
        [$exams, $attempt, $section] = $this->writeBothTasks($user->id);
        $finished = $exams->finish($attempt->fresh('sectionAttempts.section'), app(ScoringService::class));

        $sectionAttempt = $finished->sectionAttempts->firstWhere('exam_section_id', $section->id);
        $this->assertNull($sectionAttempt->estimated_score);
        $this->assertSame('scoring_unavailable', $sectionAttempt->status);
        $this->assertNull($finished->estimated_score);
        $this->assertSame(0, ExamScore::where('exam_attempt_id', $finished->id)
            ->get()->reject(fn (ExamScore $s) => ExamService::isResponseCriterion($s->criterion))->count());
    }

    public function test_a_partial_rubric_from_the_model_is_rejected(): void
    {
        $this->mock(AiOrchestrator::class, function ($mock) {
            $mock->shouldReceive('text')->andReturn(new TextResult(ok: true, json: [
                // Only one of the four criteria came back.
                'criteria' => [['code' => 'lexical_resource', 'score' => 7.0, 'rationale' => 'good range']],
            ]));
        });

        $user = $this->learner();
        [$exams, $attempt, $section] = $this->writeBothTasks($user->id);
        $finished = $exams->finish($attempt->fresh('sectionAttempts.section'), app(ScoringService::class));

        $sectionAttempt = $finished->sectionAttempts->firstWhere('exam_section_id', $section->id);
        $this->assertNull($sectionAttempt->estimated_score);
        $this->assertSame('scoring_unavailable', $sectionAttempt->status);
    }

    public function test_a_deterministically_marked_section_is_not_flagged_as_ai_estimated(): void
    {
        $this->mock(AiOrchestrator::class, fn ($m) => $m->shouldNotReceive('text'));

        $user = $this->learner();
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

        $finished = $exams->finish($attempt->fresh('sectionAttempts.section'), app(ScoringService::class));

        $this->assertFalse((bool) $finished->is_ai_estimated);

        $payload = (new ExamAttemptResource($finished->load(['examType', 'sectionAttempts.section'])))
            ->toArray(Request::create('/'));

        // Deterministic, but still never an official result.
        $this->assertTrue($payload['estimate']['is_estimate']);
        $this->assertFalse($payload['estimate']['is_official']);
        $this->assertStringContainsString('not an official result', $payload['estimate']['disclaimer']);
    }

    public function test_unattempted_sections_are_projected_from_the_curriculum_and_labelled(): void
    {
        $this->mock(AiOrchestrator::class, fn ($m) => $m->shouldNotReceive('text'));

        $user = $this->learner();
        $b2 = \App\Models\CefrLevel::where('code', 'B2')->firstOrFail();

        // The learner already has a measured level from placement and daily
        // practice; the exam layer reuses it rather than starting from zero.
        foreach (Skill::whereIn('code', ['listening', 'reading', 'writing', 'speaking'])->get() as $skill) {
            LearnerSkillState::create([
                'user_id' => $user->id,
                'skill_id' => $skill->id,
                'cefr_level_id' => $b2->id,
                'ability' => 1.0,
                'ability_se' => 0.3,
            ]);
        }

        $section = $this->section('ielts_academic', 'listening');
        $task = $this->objectiveTask($section, 'multiple_choice', [
            ['stem' => 'Q1', 'options' => ['a', 'b'], 'correct' => 0],
            ['stem' => 'Q2', 'options' => ['a', 'b'], 'correct' => 0],
        ]);

        $exams = app(ExamService::class);
        $attempt = $exams->start($user->id, $this->examType('ielts_academic'), ExamService::MODE_SECTION, $section->id);
        $exams->nextTask($attempt);
        $exercises = $exams->taskExercises($task);
        $exams->submitResponse($attempt->fresh('sectionAttempts.section'), $task, [
            'answers' => $exercises->mapWithKeys(fn ($e) => [
                $e->id => ['selected' => $e->options->firstWhere('is_correct', true)->id],
            ])->all(),
        ]);

        $finished = $exams->finish($attempt->fresh('sectionAttempts.section'), app(ScoringService::class));

        $projected = $finished->sectionAttempts->where('status', ExamService::STATUS_PROJECTED);
        $this->assertCount(3, $projected, 'reading, writing and speaking were not sat');
        // B2 maps to IELTS 5.01-6.50, whose midpoint snaps to band 6.0.
        $this->assertSame(6.0, (float) $projected->first()->estimated_score);

        $this->assertNotNull($finished->estimated_score);
        $this->assertTrue((bool) $finished->is_ai_estimated, 'a projected section is not a measured one');

        $payload = (new ExamAttemptResource($finished->load(['examType', 'sectionAttempts.section'])))
            ->toArray(Request::create('/'));

        $this->assertEqualsCanonicalizing(['reading', 'writing', 'speaking'], $payload['estimate']['projected_sections']);
        $this->assertStringContainsString('did not attempt', $payload['estimate']['disclaimer']);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
