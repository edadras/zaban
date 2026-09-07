<?php

namespace App\Services\Classroom;

use App\Models\Assignment;
use App\Models\AssignmentResponse;
use App\Models\AssignmentSubmission;
use App\Models\SpeechAttempt;
use App\Models\WritingAttempt;
use App\Services\Writing\WritingAnalysisService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The first pass at marking.
 *
 * Two of these are not AI at all and should not be: twenty multiple-choice
 * items are marked by arithmetic, and a machine asked to score them would be
 * slower, dearer, and occasionally wrong about which of two identical answers
 * was right.
 *
 * Where a judgement really is needed, this calls the analysers the product
 * already has - the same ones that mark a learner's own practice - rather than
 * a second marking path that would drift away from them. That matters more
 * than it sounds: a learner whose homework is scored 60 and whose identical
 * practice is scored 80 has learnt nothing except that the numbers are noise.
 *
 * Nothing here returns work to a learner. It writes `ai_score` and
 * `ai_feedback` next to, never over, the coach's own columns, and the coach
 * releases. The one exception is an assignment explicitly set to auto-release,
 * which is for the arithmetic kind.
 */
class HomeworkMarker
{
    public function __construct(private readonly WritingAnalysisService $writing) {}

    public function mark(AssignmentSubmission $submission): AssignmentSubmission
    {
        $assignment = $submission->assignment;

        if (! $assignment->canBeMarkedByMachine() || ! $submission->isHandedIn()) {
            return $submission;
        }

        $submission->forceFill(['status' => AssignmentSubmission::MARKING])->save();

        try {
            $result = match ($assignment->kind) {
                Assignment::EXERCISES => $this->markExercises($submission),
                Assignment::WRITING, Assignment::UPLOAD => $this->markWriting($submission),
                Assignment::SPEAKING => $this->markSpeaking($submission),
                default => null,
            };
        } catch (\Throwable $e) {
            Log::warning('homework marking failed', [
                'submission' => $submission->id,
                'error' => $e->getMessage(),
            ]);
            $result = null;
        }

        if ($result === null) {
            return $this->couldNotMark($submission, 'The work could not be marked automatically.');
        }

        [$score, $feedback, $model] = $result;

        $submission->forceFill([
            'ai_score' => round($score, 2),
            'ai_feedback' => $feedback,
            'ai_model' => $model,
            'ai_marked_at' => now(),
            'ai_error' => null,
            'status' => AssignmentSubmission::MARKED,
        ])->save();

        // Only where the coach said the arithmetic speaks for itself.
        if ($assignment->auto_release) {
            $submission->forceFill([
                'score' => $submission->ai_score,
                'marked_at' => now(),
                'returned_at' => now(),
                'status' => AssignmentSubmission::RETURNED,
            ])->save();
        }

        return $submission;
    }

    /**
     * Whether this submission is still waiting on something else.
     *
     * A spoken piece of homework is analysed by the speech pipeline on its own
     * schedule, so the marker may arrive first. The job retries rather than
     * recording a failure it would have to be told to forget.
     */
    public function isWaiting(AssignmentSubmission $submission): bool
    {
        if ($submission->assignment->kind !== Assignment::SPEAKING) {
            return false;
        }

        $attempt = $submission->speechAttempt;

        return $attempt !== null && $attempt->overall_score === null && $attempt->status !== 'failed';
    }

    // --------------------------------------------------------- the kinds

    /**
     * Arithmetic, and deliberately so.
     *
     * @return array{0: float, 1: array, 2: ?string}
     */
    private function markExercises(AssignmentSubmission $submission): array
    {
        $assignment = $submission->assignment;
        $items = $assignment->items;

        $earned = 0.0;
        $available = 0.0;
        $wrong = [];

        DB::transaction(function () use ($items, $submission, &$earned, &$available, &$wrong) {
            foreach ($items as $item) {
                $available += $item->points;

                $response = AssignmentResponse::where('assignment_submission_id', $submission->id)
                    ->where('assignment_item_id', $item->id)
                    ->first();

                // An unanswered question is wrong, not unmarked: leaving it
                // blank is an answer a coach needs to see counted.
                $correct = $response !== null && $this->matches($item->correct_options, $response->selected_options);

                if ($correct) {
                    $earned += $item->points;
                } else {
                    $wrong[] = $item->position + 1;
                }

                $response?->forceFill([
                    'is_correct' => $correct,
                    'score' => $correct ? $item->points : 0,
                ])->save();
            }
        });

        $score = $available > 0 ? ($earned / $available) * $assignment->points : 0.0;

        return [
            $score,
            [
                'kind' => 'exercises',
                'correct' => $items->count() - count($wrong),
                'total' => $items->count(),
                'wrong_positions' => $wrong,
                'summary' => count($wrong) === 0
                    ? 'Every item correct.'
                    : count($wrong).' of '.$items->count().' still to look at.',
            ],
            null,
        ];
    }

    /**
     * A written piece, marked by the writing analyser.
     *
     * A photographed page reaches this already confirmed by the learner - the
     * writing pipeline will not mark a machine's reading of somebody's
     * handwriting, because that penalises them for the recogniser's mistakes
     * rather than their own.
     *
     * @return array{0: float, 1: array, 2: ?string}|null
     */
    private function markWriting(AssignmentSubmission $submission): ?array
    {
        $attempt = $submission->writingAttempt;

        if ($attempt === null) {
            return null;
        }

        if ($attempt->status !== WritingAttempt::STATUS_SCORED) {
            $this->writing->analyse($attempt);
            $attempt->refresh();
        }

        if ($attempt->overall_score === null) {
            return null;
        }

        $points = $submission->assignment->points;

        return [
            ((float) $attempt->overall_score / 100) * $points,
            [
                'kind' => 'writing',
                'writing_attempt_id' => $attempt->id,
                'scores' => [
                    'overall' => (float) $attempt->overall_score,
                    'task_achievement' => $attempt->task_achievement_score,
                    'coherence' => $attempt->coherence_score,
                    'grammar' => $attempt->grammar_score,
                    'vocabulary' => $attempt->vocabulary_score,
                    'mechanics' => $attempt->mechanics_score,
                ],
                'summary' => $attempt->feedback['summary'] ?? null,
                'strengths' => $attempt->feedback['strengths'] ?? [],
                'next_steps' => $attempt->feedback['next_steps'] ?? [],
                'correction_count' => is_array($attempt->corrections) ? count($attempt->corrections) : 0,
            ],
            $attempt->analyser,
        ];
    }

    /**
     * A spoken piece, read off the speech attempt the learner already uploaded.
     *
     * @return array{0: float, 1: array, 2: ?string}|null
     */
    private function markSpeaking(AssignmentSubmission $submission): ?array
    {
        /** @var SpeechAttempt|null $attempt */
        $attempt = $submission->speechAttempt;

        if ($attempt === null || $attempt->overall_score === null) {
            return null;
        }

        $points = $submission->assignment->points;

        return [
            ((float) $attempt->overall_score / 100) * $points,
            [
                'kind' => 'speaking',
                'speech_attempt_id' => $attempt->id,
                'scores' => array_filter([
                    'overall' => (float) $attempt->overall_score,
                    'pronunciation' => $attempt->pronunciation_score,
                    'fluency' => $attempt->fluency_score,
                    'completeness' => $attempt->completeness_score,
                ], fn ($v) => $v !== null),
                'transcript' => $attempt->transcript,
            ],
            // The speech pipeline records which aligner produced the scores.
            $attempt->aligner,
        ];
    }

    // ------------------------------------------------------------- private

    /** @param  array<int,int>|null  $expected */
    private function matches(?array $expected, ?array $given): bool
    {
        if ($expected === null || $expected === []) {
            return false;
        }

        $a = collect($expected)->map(fn ($v) => (int) $v)->sort()->values()->all();
        $b = collect($given ?? [])->map(fn ($v) => (int) $v)->sort()->values()->all();

        return $a === $b;
    }

    private function couldNotMark(AssignmentSubmission $submission, string $why): AssignmentSubmission
    {
        // Back to submitted, not stuck in `marking`: the coach's pile should
        // show it as waiting for them rather than as something in progress
        // that will never finish.
        $submission->forceFill([
            'status' => AssignmentSubmission::SUBMITTED,
            'ai_error' => $why,
            'ai_marked_at' => now(),
        ])->save();

        return $submission;
    }
}
