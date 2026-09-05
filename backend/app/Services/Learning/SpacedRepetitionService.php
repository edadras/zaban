<?php

namespace App\Services\Learning;

use App\Models\LearnerConcept;
use App\Models\LearnerReview;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Personalised review scheduling (SM-2 derived, with per-learner adaptation).
 *
 * Intervals are not fixed per card: the ease factor moves with the learner's own
 * history, so a word someone finds hard comes back sooner for them than for
 * someone who finds it easy.
 */
class SpacedRepetitionService
{
    private const MIN_EASE = 1.3;
    private const MAX_EASE = 2.8;
    private const LAPSE_INTERVAL_DAYS = 1;

    /** Early steps in days; afterwards the interval is ease-scaled. */
    private const LEARNING_STEPS = [1, 3, 7];

    /**
     * Update the schedule on the concept state. Caller saves.
     */
    public function schedule(LearnerConcept $state, bool $correct, float $score = 1.0, int $hints = 0): void
    {
        $quality = $this->quality($correct, $score, $hints);
        $ease = (float) ($state->ease_factor ?: 2.5);

        if ($quality < 3.0) {
            // A lapse resets the ladder but keeps some of the learner's history:
            // ease drops rather than resetting, so repeated lapses compound.
            $state->repetition_number = 0;
            $state->interval_days = self::LAPSE_INTERVAL_DAYS;
            $state->ease_factor = round(max(self::MIN_EASE, $ease - 0.20), 3);
        } else {
            $n = (int) $state->repetition_number;
            $interval = $n < count(self::LEARNING_STEPS)
                ? self::LEARNING_STEPS[$n]
                : (int) max(1, round(((int) $state->interval_days) * $ease));

            $state->repetition_number = $n + 1;
            $state->interval_days = min($interval, 365);
            $state->ease_factor = round(
                min(self::MAX_EASE, max(self::MIN_EASE,
                    $ease + (0.1 - (5 - $quality) * (0.08 + (5 - $quality) * 0.02)))),
                3,
            );
        }

        $state->next_review_at = now()->addDays(max(1, (int) $state->interval_days));
        $state->memory_strength = $this->strength($state);
        $state->decay_score = $this->forgettingProbability($state, now());
    }

    /**
     * Probability the learner can no longer retrieve this concept, from the
     * exponential forgetting curve. Drives review prioritisation.
     */
    public function forgettingProbability(LearnerConcept $state, ?Carbon $at = null): float
    {
        $at ??= now();
        $last = $state->last_seen_at ?? $state->first_seen_at;
        if (! $last) {
            return 1.0;
        }
        $elapsedDays = max(0.0, $last->floatDiffInDays($at));
        $strength = max(0.4, (float) $state->memory_strength ?: 1.0);

        return round(max(0.0, min(1.0, 1 - exp(-$elapsedDays / ($strength * 1.8)))), 3);
    }

    /** Reviews genuinely due now, hardest-hit first. */
    public function due(int $userId, int $limit = 30): Collection
    {
        return LearnerConcept::with('concept')
            ->where('user_id', $userId)
            ->whereNotNull('next_review_at')
            ->where('next_review_at', '<=', now())
            ->orderBy('next_review_at')
            ->limit($limit)
            ->get()
            ->sortByDesc(fn (LearnerConcept $c) => $this->forgettingProbability($c))
            ->values();
    }

    public function dueCount(int $userId): int
    {
        return LearnerConcept::where('user_id', $userId)
            ->whereNotNull('next_review_at')
            ->where('next_review_at', '<=', now())
            ->count();
    }

    /**
     * Queue an out-of-band review, e.g. after a mistake the engine wants to
     * revisit sooner than the ladder would.
     */
    public function queue(LearnerConcept $state, string $trigger = 'spaced', ?Carbon $when = null): LearnerReview
    {
        return LearnerReview::updateOrCreate(
            [
                'user_id' => $state->user_id,
                'learner_concept_id' => $state->id,
                'status' => 'due',
            ],
            [
                'scheduled_for' => $when ?? $state->next_review_at ?? now(),
                'trigger' => $trigger,
            ],
        );
    }

    private function strength(LearnerConcept $s): float
    {
        // Strength grows with successful repetitions and with the ease the
        // learner has demonstrated on this specific concept.
        $base = log(1 + max(0, (int) $s->repetition_number)) * 2.2;

        return round(max(0.5, $base * ((float) $s->ease_factor / 2.5)), 3);
    }

    private function quality(bool $correct, float $score, int $hints): float
    {
        if (! $correct) {
            return $score > 0 ? 2.0 : 1.0;
        }

        return max(3.0, 5.0 - (0.5 * $hints) - ((1 - $score) * 1.5));
    }
}
