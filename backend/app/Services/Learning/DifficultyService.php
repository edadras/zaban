<?php

namespace App\Services\Learning;

use App\Models\Exercise;
use App\Models\LearnerProfile;
use App\Models\LearnerSkillState;

/**
 * Item selection against learner ability.
 *
 * Ability and item difficulty share one logit scale, so success probability is a
 * 2-parameter logistic. The engine aims for the 70-85% band: below it the
 * learner stalls, above it nothing is being learned.
 */
class DifficultyService
{
    public const TARGET_MIN = 0.70;
    public const TARGET_MAX = 0.85;

    /** How often to deliberately overshoot the band with a stretch item. */
    private const CHALLENGE_RATE = 0.12;

    public function successProbability(float $ability, float $difficulty, float $discrimination = 1.0, float $guessing = 0.0): float
    {
        $logit = $discrimination * ($ability - $difficulty);
        $p = 1 / (1 + exp(-$logit));

        return round($guessing + (1 - $guessing) * $p, 4);
    }

    /** The difficulty that lands exactly on a target success probability. */
    public function difficultyForTarget(float $ability, float $target = 0.775, float $discrimination = 1.0): float
    {
        $target = min(0.99, max(0.01, $target));

        return round($ability - (log($target / (1 - $target)) / max(0.1, $discrimination)), 3);
    }

    public function abilityFor(int $userId, ?int $skillId = null): float
    {
        if ($skillId) {
            $skill = LearnerSkillState::where('user_id', $userId)->where('skill_id', $skillId)->first();
            if ($skill) {
                return (float) $skill->ability;
            }
        }

        return (float) (LearnerProfile::where('user_id', $userId)->value('ability') ?? 0.0);
    }

    /**
     * Pick the item whose predicted success sits closest to the middle of the
     * target band, occasionally reaching higher so progress stays visible.
     *
     * @param  \Illuminate\Support\Collection<int, Exercise>  $candidates
     */
    public function choose($candidates, float $ability, bool $allowChallenge = true): ?Exercise
    {
        if ($candidates->isEmpty()) {
            return null;
        }

        $target = $allowChallenge && (mt_rand() / mt_getrandmax()) < self::CHALLENGE_RATE
            ? 0.60                       // a deliberate stretch
            : (self::TARGET_MIN + self::TARGET_MAX) / 2;

        return $candidates
            ->sortBy(fn (Exercise $e) => abs(
                $this->successProbability(
                    $ability,
                    (float) $e->difficulty,
                    (float) ($e->discrimination ?: 1.0),
                    (float) $e->guessing,
                ) - $target
            ))
            ->first();
    }

    public function isInZone(float $ability, Exercise $e): bool
    {
        $p = $this->successProbability(
            $ability, (float) $e->difficulty,
            (float) ($e->discrimination ?: 1.0), (float) $e->guessing,
        );

        return $p >= self::TARGET_MIN && $p <= self::TARGET_MAX;
    }

    /**
     * Re-estimate ability from one graded response (online gradient step).
     * Used between placement items and after ordinary practice.
     *
     * @return array{0: float, 1: float} new ability, new standard error
     */
    public function updateAbility(float $ability, float $se, Exercise $item, bool $correct): array
    {
        $a = (float) ($item->discrimination ?: 1.0);
        $p = $this->successProbability($ability, (float) $item->difficulty, $a, (float) $item->guessing);

        // Fisher information for a 2PL item; more informative items move the
        // estimate further and shrink the error faster.
        $info = max(1e-4, $a * $a * $p * (1 - $p));
        $step = ($correct ? 1 : 0) - $p;

        $newAbility = $ability + ($step / $info) * min(0.35, $se * $se);
        $newAbility = max(-6.0, min(6.0, $newAbility));
        $newSe = max(0.18, 1 / sqrt((1 / max(0.04, $se * $se)) + $info));

        return [round($newAbility, 4), round($newSe, 4)];
    }

    public function information(float $ability, Exercise $item): float
    {
        $a = (float) ($item->discrimination ?: 1.0);
        $p = $this->successProbability($ability, (float) $item->difficulty, $a, (float) $item->guessing);

        return round($a * $a * $p * (1 - $p), 5);
    }
}
