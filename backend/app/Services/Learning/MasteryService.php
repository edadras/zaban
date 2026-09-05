<?php

namespace App\Services\Learning;

use App\Models\Concept;
use App\Models\LearnerConcept;
use App\Models\ReviewHistory;
use Illuminate\Support\Facades\DB;

/**
 * Owns the learner's mastery state. The backend is the only place mastery is
 * computed - the client never sends it.
 *
 * Mastery is evidence of durable retrieval, not of a correct answer. Two rules
 * enforce that:
 *   1. a single success cannot push a concept past "introduced"; and
 *   2. successes only count toward the higher bands when they are spaced apart
 *      in time, because answering the same item twice in one sitting shows
 *      short-term recall, not learning.
 */
class MasteryService
{
    /** Band ceilings from spec section 12. */
    public const UNKNOWN = 0.00;
    public const INTRODUCED = 0.20;
    public const DEVELOPING = 0.40;
    public const COMPETENT = 0.60;
    public const STRONG = 0.80;
    public const MASTERED = 0.95;

    /** A retrieval only counts as "spaced" once this much time has passed. */
    private const SPACING_HOURS = 20;

    public function __construct(private SpacedRepetitionService $srs) {}

    public function stateFor(int $userId, int $conceptId): LearnerConcept
    {
        return LearnerConcept::firstOrCreate(
            ['user_id' => $userId, 'concept_id' => $conceptId],
            ['first_seen_at' => now(), 'ease_factor' => 2.5],
        );
    }

    /**
     * Record one graded encounter and return the updated state.
     *
     * @param  float  $score  0..1, partial credit aware
     */
    public function record(
        int $userId,
        int $conceptId,
        bool $correct,
        float $score = 1.0,
        int $hintsUsed = 0,
        ?int $responseMs = null,
        ?float $itemDifficulty = null,
    ): LearnerConcept {
        return DB::transaction(function () use ($userId, $conceptId, $correct, $score, $hintsUsed, $responseMs, $itemDifficulty) {
            /** @var LearnerConcept $state */
            $state = LearnerConcept::where('user_id', $userId)
                ->where('concept_id', $conceptId)
                ->lockForUpdate()
                ->first() ?? $this->stateFor($userId, $conceptId);

            $before = (float) $state->mastery_score;
            $intervalBefore = (int) $state->interval_days;

            $spaced = $state->last_success_at === null
                || $state->last_success_at->diffInHours(now()) >= self::SPACING_HOURS;

            $state->exposure_count++;
            $state->last_seen_at = now();
            if ($state->first_seen_at === null) {
                $state->first_seen_at = now();
            }
            if ($hintsUsed > 0) {
                $state->hint_count += $hintsUsed;
            }
            if ($responseMs !== null) {
                $n = max(1, $state->exposure_count);
                $state->avg_response_ms = (int) round(
                    ((($state->avg_response_ms ?? $responseMs) * ($n - 1)) + $responseMs) / $n
                );
            }

            if ($correct) {
                $state->correct_count++;
                $state->consecutive_correct++;
                $state->last_success_at = now();
            } else {
                $state->incorrect_count++;
                $state->consecutive_correct = 0;
            }

            $state->mastery_score = $this->nextMastery($state, $correct, $score, $hintsUsed, $spaced);
            $state->confidence = $this->confidence($state);
            $state->difficulty_performance = $this->trackDifficulty($state, $itemDifficulty, $correct);

            if ($state->mastery_score >= self::MASTERED && $state->mastered_at === null) {
                $state->mastered_at = now();
            }
            if ($state->mastery_score < self::MASTERED) {
                $state->mastered_at = null;
            }

            $this->srs->schedule($state, $correct, $score, $hintsUsed);
            $state->save();

            ReviewHistory::create([
                'user_id' => $userId,
                'concept_id' => $conceptId,
                'was_successful' => $correct,
                'quality' => $this->recallQuality($correct, $score, $hintsUsed),
                'mastery_before' => $before,
                'mastery_after' => $state->mastery_score,
                'interval_days_before' => $intervalBefore,
                'interval_days_after' => $state->interval_days,
                'response_ms' => $responseMs,
                'reviewed_at' => now(),
            ]);

            return $state;
        });
    }

    /**
     * A correct answer moves mastery up by a fraction of the remaining distance
     * to the next band; an incorrect answer drops it hard. Unspaced successes are
     * capped so cramming cannot manufacture mastery.
     */
    private function nextMastery(LearnerConcept $s, bool $correct, float $score, int $hints, bool $spaced): float
    {
        $current = (float) $s->mastery_score;

        if (! $correct) {
            // A failure means the concept is not held. Fall back toward
            // 'developing' rather than to zero - the learner has still met it.
            return round(max(self::INTRODUCED, $current * 0.55), 3);
        }

        // Hints mean assisted retrieval, which is worth less.
        $credit = $score * (1 - min(0.6, 0.25 * $hints));

        $ceiling = match (true) {
            $s->consecutive_correct <= 1 => self::INTRODUCED,
            ! $spaced => min(self::DEVELOPING, max($current, self::DEVELOPING)),
            $s->consecutive_correct === 2 => self::COMPETENT,
            $s->consecutive_correct === 3 => self::STRONG,
            default => self::MASTERED,
        };

        $target = $current + (($ceiling - $current) * 0.6 * $credit);

        return round(max($current, min($ceiling, $target)), 3);
    }

    /**
     * How much we trust the mastery figure. Grows with evidence, shrinks when
     * results are mixed.
     */
    private function confidence(LearnerConcept $s): float
    {
        $n = $s->correct_count + $s->incorrect_count;
        if ($n === 0) {
            return 0.0;
        }
        $volume = 1 - exp(-$n / 5);
        $rate = $s->correct_count / $n;
        $consistency = 1 - (4 * $rate * (1 - $rate));   // 1 at all-right/all-wrong, 0 at 50/50

        return round(max(0.0, min(1.0, $volume * (0.5 + 0.5 * $consistency))), 3);
    }

    /** Success rate bucketed by item difficulty: tells "knows it" from "guesses easy ones". */
    private function trackDifficulty(LearnerConcept $s, ?float $difficulty, bool $correct): array
    {
        $buckets = $s->difficulty_performance ?? [];
        if ($difficulty === null) {
            return $buckets;
        }
        $key = match (true) {
            $difficulty < -1.5 => 'easy',
            $difficulty < 0.5 => 'medium',
            default => 'hard',
        };
        $buckets[$key]['seen'] = ($buckets[$key]['seen'] ?? 0) + 1;
        $buckets[$key]['correct'] = ($buckets[$key]['correct'] ?? 0) + ($correct ? 1 : 0);

        return $buckets;
    }

    private function recallQuality(bool $correct, float $score, int $hints): float
    {
        if (! $correct) {
            return $score > 0 ? 2.0 : 1.0;
        }

        return round(max(3.0, 5.0 - (0.5 * $hints) - ((1 - $score) * 1.5)), 2);
    }

    /** Aggregate mastery across the concepts a learner has actually met. */
    public function overall(int $userId): float
    {
        $avg = LearnerConcept::where('user_id', $userId)->avg('mastery_score');

        return round((float) ($avg ?? 0), 4);
    }

    /** @return \Illuminate\Support\Collection<int, LearnerConcept> */
    public function weakest(int $userId, int $limit = 20)
    {
        return LearnerConcept::with('concept')
            ->where('user_id', $userId)
            ->where('exposure_count', '>', 0)
            ->where('mastery_score', '<', self::COMPETENT)
            ->orderBy('mastery_score')
            ->orderByDesc('incorrect_count')
            ->limit($limit)
            ->get();
    }
}
