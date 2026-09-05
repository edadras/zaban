<?php

namespace App\Services\Learning;

/**
 * Re-estimates an item's difficulty from how learners actually answered it.
 *
 * Every item's difficulty starts as a guess: how long the term is, how many
 * words it spans, how often the corpus reuses it, ranked inside the book's CEFR
 * range. That is a reasonable prior and nothing more, and the placement test and
 * the whole adaptive loop rest on it.
 *
 * The evidence to do better was already being collected - each attempt records
 * the learner's ability at the time it was made - and nothing was reading it.
 * The grading path even carries a comment promising "live item calibration".
 * This is that.
 *
 * Two properties matter more than sophistication here. It must not thrash an
 * item on thin evidence, so the estimate is shrunk toward the prior by sample
 * size. And it must notice when an item is not merely hard but broken: an item
 * that people well above its difficulty keep failing is usually an item with two
 * correct answers, which is the exact defect this bank shipped with.
 */
class ItemCalibrator
{
    /**
     * Attempts needed before an item is worth re-estimating at all.
     *
     * Below this the shrinkage would leave the prior almost untouched anyway,
     * and the pass is cheaper for skipping it.
     */
    public const MIN_ATTEMPTS = 20;

    /**
     * Prior weight, in attempts. With 20 attempts the estimate counts for half;
     * with 100, for five sixths.
     */
    public const PRIOR_WEIGHT = 20.0;

    /** No single pass may move an item further than this. */
    public const MAX_SHIFT = 1.5;

    /** Search bounds for the ability scale. */
    private const FLOOR = -6.0;

    private const CEILING = 6.0;

    /**
     * @param  array<int, array{ability: float, correct: bool}>  $attempts
     * @return array{
     *     difficulty: float,
     *     raw: float,
     *     shift: float,
     *     attempts: int,
     *     observed: float,
     *     expected: float,
     *     suspect: bool
     * }|null
     */
    public function calibrate(
        array $attempts,
        float $prior,
        float $discrimination = 1.0,
        float $guessing = 0.0,
    ): ?array {
        $n = count($attempts);
        if ($n < self::MIN_ATTEMPTS) {
            return null;
        }

        $correct = 0;
        foreach ($attempts as $a) {
            $correct += $a['correct'] ? 1 : 0;
        }

        $a = $discrimination > 0 ? $discrimination : 1.0;

        $raw = $this->solve($attempts, $correct, $a, $guessing);
        $shrunk = (($n * $raw) + (self::PRIOR_WEIGHT * $prior)) / ($n + self::PRIOR_WEIGHT);

        $bounded = max($prior - self::MAX_SHIFT, min($prior + self::MAX_SHIFT, $shrunk));

        return [
            'difficulty' => round($bounded, 3),
            'raw' => round($raw, 3),
            'shift' => round($bounded - $prior, 3),
            'attempts' => $n,
            'observed' => round($correct / $n, 3),
            'expected' => round($this->expected($attempts, $prior, $a, $guessing) / $n, 3),
            'suspect' => $this->looksBroken($attempts, $prior, $a, $guessing),
        ];
    }

    /**
     * The difficulty at which the model would have predicted exactly the number
     * of correct answers we saw.
     *
     * The expected count falls monotonically as difficulty rises, so a bisection
     * is exact to the tolerance and cannot diverge the way a Newton step can on
     * a sample where everyone succeeded or everyone failed.
     */
    private function solve(array $attempts, int $correct, float $a, float $c): float
    {
        $low = self::FLOOR;
        $high = self::CEILING;

        // Everyone right, or everyone wrong: the likelihood has no interior
        // maximum, so peg to the bound rather than pretending to a number.
        if ($this->expected($attempts, $high, $a, $c) > $correct) {
            return $high;
        }
        if ($this->expected($attempts, $low, $a, $c) < $correct) {
            return $low;
        }

        for ($i = 0; $i < 60; $i++) {
            $mid = ($low + $high) / 2;
            if ($this->expected($attempts, $mid, $a, $c) > $correct) {
                $low = $mid;
            } else {
                $high = $mid;
            }
        }

        return ($low + $high) / 2;
    }

    /** How many of these attempts the model expects to be correct at difficulty b. */
    private function expected(array $attempts, float $b, float $a, float $c): float
    {
        $sum = 0.0;
        foreach ($attempts as $attempt) {
            $sum += $this->probability((float) $attempt['ability'], $b, $a, $c);
        }

        return $sum;
    }

    private function probability(float $ability, float $b, float $a, float $c): float
    {
        return $c + (1 - $c) / (1 + exp(-$a * ($ability - $b)));
    }

    /**
     * Is this item hard, or is it wrong?
     *
     * A hard item is one that people below it fail and people above it pass. An
     * item that learners well above its difficulty fail at close to chance is
     * not measuring difficulty at all - most often because more than one of its
     * options is correct, which is how this bank's first release marked a C1
     * speaker down to A1. Those are worth a human's attention rather than a
     * quiet shift along the scale.
     */
    private function looksBroken(array $attempts, float $prior, float $a, float $c): bool
    {
        $strong = array_values(array_filter(
            $attempts,
            fn ($attempt) => (float) $attempt['ability'] >= $prior + 1.0,
        ));

        if (count($strong) < 8) {
            return false;
        }

        $correct = 0;
        foreach ($strong as $attempt) {
            $correct += $attempt['correct'] ? 1 : 0;
        }

        // At a full logit above the item, the model expects roughly three in
        // four. Half or fewer is not difficulty.
        return ($correct / count($strong)) <= 0.5;
    }
}
