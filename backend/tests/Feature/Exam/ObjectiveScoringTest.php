<?php

namespace Tests\Feature\Exam;

use App\AI\AiOrchestrator;
use App\Models\ExamScore;
use App\Models\ExerciseAttempt;
use App\Models\LearnerError;
use App\Services\Exam\ExamService;
use App\Services\Exam\ScoringService;

/**
 * Listening and reading must mark the same way every time, from the answer key
 * alone. If a model is ever consulted on this path the estimate stops being
 * reproducible, so that is asserted too.
 */
class ObjectiveScoringTest extends ExamTestCase
{
    public function test_a_multiple_choice_section_is_marked_from_the_answer_key(): void
    {
        // Any AI call on an objective path is a bug, not a fallback.
        $this->mock(AiOrchestrator::class, fn ($m) => $m->shouldNotReceive('text'));

        $user = $this->learner();
        $section = $this->section('ielts_academic', 'listening');
        $task = $this->objectiveTask($section, 'multiple_choice', [
            ['stem' => 'What time does the tour start?', 'options' => ['09:00', '10:00', '11:00'], 'correct' => 1],
            ['stem' => 'Where do participants meet?', 'options' => ['Library', 'Main gate', 'Car park'], 'correct' => 1],
            ['stem' => 'What should they bring?', 'options' => ['Passport', 'Camera', 'Umbrella'], 'correct' => 0],
            ['stem' => 'How long does it last?', 'options' => ['1 hour', '2 hours', '3 hours'], 'correct' => 2],
        ]);

        $exams = app(ExamService::class);
        $attempt = $exams->start($user->id, $this->examType('ielts_academic'), ExamService::MODE_SECTION, $section->id);
        $exams->nextTask($attempt);

        $exercises = $exams->taskExercises($task);
        $answers = [];
        foreach ($exercises as $i => $exercise) {
            $correct = $exercise->options->firstWhere('is_correct', true);
            $wrong = $exercise->options->firstWhere('is_correct', false);
            // Three right, the last one deliberately wrong.
            $answers[$exercise->id] = ['selected' => $i === 3 ? $wrong->id : $correct->id];
        }

        $record = $exams->submitResponse($attempt->fresh('sectionAttempts.section'), $task, ['answers' => $answers], 240);

        $this->assertSame(3.0, $record['raw_score']);
        $this->assertSame(4, $record['items_marked']);
        $this->assertCount(4, $record['items']);

        $this->assertSame(4, ExerciseAttempt::where('user_id', $user->id)->count());
        $this->assertSame(3, ExerciseAttempt::where('user_id', $user->id)->where('is_correct', true)->count());

        $stored = ExamScore::where('exam_attempt_id', $attempt->id)
            ->where('criterion', ExamService::RESPONSE_CRITERION.$task->id)
            ->firstOrFail();
        $this->assertSame(240, $stored->evidence['seconds_used']);
        $this->assertSame('multiple_choice', $stored->evidence['exam_task_type']);
    }

    public function test_the_section_band_is_deterministic_and_reproducible(): void
    {
        $this->mock(AiOrchestrator::class, fn ($m) => $m->shouldNotReceive('text'));

        $bands = [];
        for ($run = 0; $run < 2; $run++) {
            $user = $this->learner();
            $section = $this->section('ielts_academic', 'listening');
            $task = $this->objectiveTask($section, 'multiple_choice', [
                ['stem' => 'Q1', 'options' => ['a', 'b'], 'correct' => 0],
                ['stem' => 'Q2', 'options' => ['a', 'b'], 'correct' => 1],
                ['stem' => 'Q3', 'options' => ['a', 'b'], 'correct' => 0],
                ['stem' => 'Q4', 'options' => ['a', 'b'], 'correct' => 1],
            ]);

            $exams = app(ExamService::class);
            $attempt = $exams->start($user->id, $this->examType('ielts_academic'), ExamService::MODE_SECTION, $section->id);
            $exams->nextTask($attempt);

            $exercises = $exams->taskExercises($task);
            $answers = [];
            foreach ($exercises as $i => $exercise) {
                $option = $i === 3
                    ? $exercise->options->firstWhere('is_correct', false)
                    : $exercise->options->firstWhere('is_correct', true);
                $answers[$exercise->id] = ['selected' => $option->id];
            }
            $exams->submitResponse($attempt->fresh('sectionAttempts.section'), $task, ['answers' => $answers]);

            $sectionAttempt = $attempt->fresh('sectionAttempts.section')->sectionAttempts
                ->firstWhere('exam_section_id', $section->id);

            $result = app(ScoringService::class)->scoreSection($attempt->fresh('sectionAttempts.section'), $sectionAttempt);
            $bands[] = $result;
        }

        $this->assertSame($bands[0]['score'], $bands[1]['score']);
        $this->assertSame('answer_key', $bands[0]['source']);
        // 3 of 4 correct, projected onto the 40-question paper, is 30 raw = band 7.0.
        $this->assertSame(7.0, $bands[0]['score']);
        $this->assertTrue($bands[0]['extrapolated']);
    }

    public function test_short_answer_items_accept_spelling_variants_but_not_wrong_answers(): void
    {
        $this->mock(AiOrchestrator::class, fn ($m) => $m->shouldNotReceive('text'));

        $user = $this->learner();
        $section = $this->section('ielts_academic', 'listening');
        $task = $this->shortAnswerTask($section, 'short_answer', [
            ['stem' => 'The library closes at ___', 'answer' => 'six thirty'],
            ['stem' => 'The fee is ___ pounds', 'answer' => 'twelve'],
        ]);

        $exams = app(ExamService::class);
        $attempt = $exams->start($user->id, $this->examType('ielts_academic'), ExamService::MODE_SECTION, $section->id);
        $exams->nextTask($attempt);

        $exercises = $exams->taskExercises($task)->values();
        $record = $exams->submitResponse($attempt->fresh('sectionAttempts.section'), $task, [
            'answers' => [
                // Case and spacing normalise; the second answer is simply wrong.
                $exercises[0]->id => ' Six  Thirty ',
                $exercises[1]->id => 'twenty',
            ],
        ]);

        $this->assertSame(1.0, $record['raw_score']);
        $this->assertTrue($record['items'][0]['is_correct']);
        $this->assertFalse($record['items'][1]['is_correct']);
    }

    public function test_exam_mistakes_are_written_into_the_shared_error_log(): void
    {
        $this->mock(AiOrchestrator::class, fn ($m) => $m->shouldNotReceive('text'));

        $user = $this->learner();
        $section = $this->section('ielts_academic', 'reading');
        $task = $this->objectiveTask($section, 'matching_headings', [
            ['stem' => 'Paragraph A heading', 'options' => ['Growth', 'Decline'], 'correct' => 0],
        ]);

        $exams = app(ExamService::class);
        $attempt = $exams->start($user->id, $this->examType('ielts_academic'), ExamService::MODE_SECTION, $section->id);
        $exams->nextTask($attempt);

        $exercise = $exams->taskExercises($task)->first();
        $exams->submitResponse($attempt->fresh('sectionAttempts.section'), $task, [
            'answers' => [$exercise->id => ['selected' => $exercise->options->firstWhere('is_correct', false)->id]],
        ]);

        $error = LearnerError::where('user_id', $user->id)->firstOrFail();
        $this->assertSame('reading', $error->error_type);
        // The exam question type is the subtype, which is what makes a recurring
        // weakness on one task type visible to the curriculum.
        $this->assertSame('matching_headings', $error->error_subtype);
        $this->assertSame('Growth', $error->expected);
    }

    public function test_an_unanswered_item_scores_zero_rather_than_being_skipped(): void
    {
        $this->mock(AiOrchestrator::class, fn ($m) => $m->shouldNotReceive('text'));

        $user = $this->learner();
        $section = $this->section('ielts_academic', 'listening');
        $task = $this->objectiveTask($section, 'multiple_choice', [
            ['stem' => 'Q1', 'options' => ['a', 'b'], 'correct' => 0],
            ['stem' => 'Q2', 'options' => ['a', 'b'], 'correct' => 0],
        ]);

        $exams = app(ExamService::class);
        $attempt = $exams->start($user->id, $this->examType('ielts_academic'), ExamService::MODE_SECTION, $section->id);
        $exams->nextTask($attempt);

        $first = $exams->taskExercises($task)->first();
        $record = $exams->submitResponse($attempt->fresh('sectionAttempts.section'), $task, [
            'answers' => [$first->id => ['selected' => $first->options->firstWhere('is_correct', true)->id]],
        ]);

        $this->assertSame(1.0, $record['raw_score']);
        $this->assertSame(2, $record['items_marked']);
        $this->assertFalse($record['items'][1]['is_correct']);
    }
}
