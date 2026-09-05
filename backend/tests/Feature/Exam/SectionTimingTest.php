<?php

namespace Tests\Feature\Exam;

use App\AI\AiOrchestrator;
use App\Services\Exam\ExamException;
use App\Services\Exam\ExamService;
use Illuminate\Support\Carbon;

/**
 * The clock is half of what an exam tests. A mock must actually close a section
 * when its time is up; practice must let the learner finish but still tell them
 * they went over.
 */
class SectionTimingTest extends ExamTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(AiOrchestrator::class, fn ($m) => $m->shouldNotReceive('text'));
        Carbon::setTestNow('2026-03-01 09:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_section_deadline_comes_from_the_seeded_duration(): void
    {
        $user = $this->learner();
        $listening = $this->section('ielts_academic', 'listening');
        $this->objectiveTask($listening, 'multiple_choice', [
            ['stem' => 'Q1', 'options' => ['a', 'b'], 'correct' => 0],
        ]);

        $exams = app(ExamService::class);
        $attempt = $exams->start($user->id, $this->examType('ielts_academic'), ExamService::MODE_MOCK);
        $next = $exams->nextTask($attempt);

        $this->assertSame(40 * 60, $next['timing']['section_allowed_seconds']);
        $this->assertSame(40 * 60, $next['timing']['section_remaining_seconds']);
        $this->assertTrue($next['timing']['enforced']);

        Carbon::setTestNow('2026-03-01 09:25:00');
        $sectionAttempt = $attempt->fresh('sectionAttempts.section')->sectionAttempts
            ->firstWhere('exam_section_id', $listening->id);
        $this->assertSame(15 * 60, $exams->remainingSeconds($sectionAttempt));
    }

    public function test_a_mock_closes_the_section_when_the_clock_runs_out_and_moves_on(): void
    {
        $user = $this->learner();
        $listening = $this->section('ielts_academic', 'listening');
        $reading = $this->section('ielts_academic', 'reading');
        $this->objectiveTask($listening, 'multiple_choice', [['stem' => 'L1', 'options' => ['a', 'b'], 'correct' => 0]]);
        $this->objectiveTask($reading, 'matching_headings', [['stem' => 'R1', 'options' => ['a', 'b'], 'correct' => 0]]);

        $exams = app(ExamService::class);
        $attempt = $exams->start($user->id, $this->examType('ielts_academic'), ExamService::MODE_MOCK);
        $exams->nextTask($attempt);

        Carbon::setTestNow('2026-03-01 09:41:00');
        $next = $exams->nextTask($attempt->fresh('sectionAttempts.section'));

        $this->assertSame($reading->id, $next['section']->id, 'the expired listening section should be behind us');

        $expired = $attempt->fresh('sectionAttempts.section')->sectionAttempts
            ->firstWhere('exam_section_id', $listening->id);
        $this->assertSame('completed', $expired->status);
        $this->assertTrue((bool) $expired->ran_out_of_time);
        $this->assertSame(40 * 60, (int) $expired->duration_seconds);
    }

    public function test_a_submission_after_the_section_deadline_is_refused_in_a_mock(): void
    {
        $user = $this->learner();
        $listening = $this->section('ielts_academic', 'listening');
        $task = $this->objectiveTask($listening, 'multiple_choice', [['stem' => 'L1', 'options' => ['a', 'b'], 'correct' => 0]]);

        $exams = app(ExamService::class);
        $attempt = $exams->start($user->id, $this->examType('ielts_academic'), ExamService::MODE_MOCK);
        $exams->nextTask($attempt);

        Carbon::setTestNow('2026-03-01 09:41:00');

        $exercise = $exams->taskExercises($task)->first();

        try {
            $exams->submitResponse($attempt->fresh('sectionAttempts.section'), $task, [
                'answers' => [$exercise->id => ['selected' => $exercise->options->first()->id]],
            ]);
            $this->fail('a submission after the buzzer should be refused');
        } catch (ExamException $e) {
            $this->assertSame('exam_section_expired', $e->errorCode);
            $this->assertSame(409, $e->status);
        }

        $this->assertSame(0, \App\Models\ExerciseAttempt::where('user_id', $user->id)->count());
    }

    public function test_practice_mode_records_the_overrun_but_still_accepts_the_answer(): void
    {
        $user = $this->learner();
        $listening = $this->section('ielts_academic', 'listening');
        $task = $this->objectiveTask($listening, 'multiple_choice', [['stem' => 'L1', 'options' => ['a', 'b'], 'correct' => 0]]);

        $exams = app(ExamService::class);
        $attempt = $exams->start($user->id, $this->examType('ielts_academic'), ExamService::MODE_PRACTICE);
        $next = $exams->nextTask($attempt);
        $this->assertFalse($next['timing']['enforced']);

        Carbon::setTestNow('2026-03-01 09:55:00');

        $exercise = $exams->taskExercises($task)->first();
        $record = $exams->submitResponse($attempt->fresh('sectionAttempts.section'), $task, [
            'answers' => [$exercise->id => ['selected' => $exercise->options->firstWhere('is_correct', true)->id]],
        ]);

        $this->assertSame(1.0, $record['raw_score']);

        $sectionAttempt = $attempt->fresh('sectionAttempts.section')->sectionAttempts
            ->firstWhere('exam_section_id', $listening->id);
        $this->assertTrue((bool) $sectionAttempt->ran_out_of_time);
        $this->assertSame('in_progress', $sectionAttempt->status);
    }

    public function test_a_mock_past_its_overall_limit_refuses_to_serve_anything(): void
    {
        $user = $this->learner();
        $listening = $this->section('ielts_academic', 'listening');
        $this->objectiveTask($listening, 'multiple_choice', [['stem' => 'L1', 'options' => ['a', 'b'], 'correct' => 0]]);

        $exams = app(ExamService::class);
        $attempt = $exams->start($user->id, $this->examType('ielts_academic'), ExamService::MODE_MOCK);
        $exams->nextTask($attempt);

        // IELTS Academic is seeded at 174 minutes end to end.
        Carbon::setTestNow('2026-03-01 12:00:00');

        $this->expectException(ExamException::class);
        $this->expectExceptionMessage('overall time limit');
        $exams->nextTask($attempt->fresh('sectionAttempts.section'));
    }

    public function test_time_management_is_recorded_per_section_on_finish(): void
    {
        $user = $this->learner();
        $listening = $this->section('ielts_academic', 'listening');
        $task = $this->objectiveTask($listening, 'multiple_choice', [['stem' => 'L1', 'options' => ['a', 'b'], 'correct' => 0]]);

        $exams = app(ExamService::class);
        $attempt = $exams->start($user->id, $this->examType('ielts_academic'), ExamService::MODE_SECTION, $listening->id);
        $exams->nextTask($attempt);

        Carbon::setTestNow('2026-03-01 09:12:00');
        $exercise = $exams->taskExercises($task)->first();
        $exams->submitResponse($attempt->fresh('sectionAttempts.section'), $task, [
            'answers' => [$exercise->id => ['selected' => $exercise->options->firstWhere('is_correct', true)->id]],
        ], 700);

        $finished = $exams->finish($attempt->fresh('sectionAttempts.section'), app(\App\Services\Exam\ScoringService::class));

        $report = $finished->time_management;
        $this->assertArrayHasKey('listening', $report['sections']);
        $this->assertSame(2400, $report['sections']['listening']['allowed_seconds']);
        $this->assertSame(720, $report['sections']['listening']['used_seconds']);
        $this->assertSame(0.3, $report['sections']['listening']['used_ratio']);
        $this->assertSame(1, $report['sections']['listening']['tasks_submitted']);
        // Half the allowed time or less, with work submitted, reads as rushed.
        $this->assertContains('listening', $report['flags']['finished_suspiciously_fast']);
    }
}
