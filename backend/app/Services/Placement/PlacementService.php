<?php

namespace App\Services\Placement;

use App\Models\CefrLevel;
use App\Models\Exercise;
use App\Models\LearnerProfile;
use App\Models\LearnerSkillState;
use App\Models\PlacementResponse;
use App\Models\PlacementSession;
use App\Models\PlacementSkillState;
use App\Models\Skill;
use App\Services\Learning\CoursePlacementService;
use App\Services\Learning\DifficultyService;
use Illuminate\Support\Facades\DB;

/**
 * Adaptive placement (computer adaptive testing).
 *
 * Not a fixed quiz: each item is chosen to be the most informative one available
 * at the learner's current estimate, and each dimension stops on its own as soon
 * as its standard error is small enough. A confident reader finishes reading in
 * four items while their speaking estimate is still being refined.
 */
class PlacementService
{
    /** Stop a dimension once the estimate is this precise. */
    private const TARGET_SE = 0.32;

    /** Everyone starts near B1: the middle of the ladder minimises expected items. */
    private const START_ABILITY = 0.0;
    private const START_SE = 1.5;

    public function __construct(
        private DifficultyService $difficulty,
        private CoursePlacementService $courses,
    ) {}

    public function start(int $userId, int $languageId): PlacementSession
    {
        $existing = PlacementSession::where('user_id', $userId)
            ->where('status', 'in_progress')->latest('id')->first();
        if ($existing) {
            return $existing;
        }

        return DB::transaction(function () use ($userId, $languageId) {
            $session = PlacementSession::create([
                'user_id' => $userId,
                'language_id' => $languageId,
                'status' => 'in_progress',
                'ability' => self::START_ABILITY,
                'ability_se' => self::START_SE,
                'max_items' => 40,
                'started_at' => now(),
            ]);

            foreach (Skill::where('assessed_in_placement', true)->get() as $skill) {
                PlacementSkillState::create([
                    'placement_session_id' => $session->id,
                    'skill_id' => $skill->id,
                    'ability' => self::START_ABILITY,
                    'ability_se' => self::START_SE,
                    'min_items' => 4,
                    'max_items' => 12,
                    'target_se' => self::TARGET_SE,
                ]);
            }

            LearnerProfile::updateOrCreate(
                ['user_id' => $userId],
                ['language_id' => $languageId, 'placement_status' => 'in_progress'],
            );

            return $session->fresh('skillStates');
        });
    }

    /**
     * The next item, or null when every dimension has converged.
     */
    public function nextItem(PlacementSession $session): ?Exercise
    {
        $state = $this->pickDimension($session);
        if (! $state) {
            return null;
        }

        $asked = PlacementResponse::where('placement_session_id', $session->id)->pluck('exercise_id')->all();

        $candidates = Exercise::query()
            ->where('is_placement_eligible', true)
            ->where('skill_id', $state->skill_id)
            ->when($asked, fn ($q) => $q->whereNotIn('id', $asked))
            ->whereNull('deleted_at')
            // Only look near the current estimate: items far from it carry
            // almost no information and waste the learner's time.
            ->whereBetween('difficulty', [(float) $state->ability - 2.0, (float) $state->ability + 2.0])
            ->limit(40)
            ->get();

        if ($candidates->isEmpty()) {
            // Nothing left in band for this skill: close it out rather than
            // asking an uninformative question.
            $this->finaliseDimension($state);

            return $this->nextItem($session->fresh('skillStates'));
        }

        // Maximum-information selection: the item that most reduces uncertainty.
        return $candidates
            ->sortByDesc(fn (Exercise $e) => $this->difficulty->information((float) $state->ability, $e))
            ->first();
    }

    /**
     * Grade one response, update that dimension and the global estimate.
     */
    public function submit(PlacementSession $session, Exercise $item, bool $correct, ?float $score = null, ?int $responseMs = null): PlacementResponse
    {
        return DB::transaction(function () use ($session, $item, $correct, $score, $responseMs) {
            /** @var PlacementSkillState $state */
            $state = PlacementSkillState::where('placement_session_id', $session->id)
                ->where('skill_id', $item->skill_id)
                ->lockForUpdate()
                ->firstOrFail();

            $abilityBefore = (float) $state->ability;
            $seBefore = (float) $state->ability_se;
            $information = $this->difficulty->information($abilityBefore, $item);

            [$abilityAfter, $seAfter] = $this->difficulty->updateAbility($abilityBefore, $seBefore, $item, $correct);

            $sequence = (int) PlacementResponse::where('placement_session_id', $session->id)->max('sequence') + 1;

            $response = PlacementResponse::create([
                'placement_session_id' => $session->id,
                'exercise_id' => $item->id,
                'skill_id' => $item->skill_id,
                'sequence' => $sequence,
                'is_correct' => $correct,
                'score' => $score ?? ($correct ? 1.0 : 0.0),
                'response_ms' => $responseMs,
                'ability_before' => $abilityBefore,
                'ability_after' => $abilityAfter,
                'se_before' => $seBefore,
                'se_after' => $seAfter,
                'item_information' => $information,
                'presented_at' => now(),
                'answered_at' => now(),
            ]);

            $state->update([
                'ability' => $abilityAfter,
                'ability_se' => $seAfter,
                'items_administered' => $state->items_administered + 1,
            ]);

            if ($this->dimensionComplete($state->fresh())) {
                $this->finaliseDimension($state->fresh());
            }

            $this->recomputeGlobal($session);

            return $response;
        });
    }

    /** True once every dimension has converged or the item cap is reached. */
    public function isComplete(PlacementSession $session): bool
    {
        $session->loadMissing('skillStates');
        if ($session->items_administered >= $session->max_items) {
            return true;
        }

        return $session->skillStates->every(fn (PlacementSkillState $s) => $s->is_complete);
    }

    /**
     * Close the session: write per-skill CEFR levels and the overall estimate
     * onto the learner profile so the curriculum can start in the right place.
     */
    public function complete(PlacementSession $session): PlacementSession
    {
        return DB::transaction(function () use ($session) {
            $session->loadMissing('skillStates');

            foreach ($session->skillStates as $state) {
                if (! $state->is_complete) {
                    $this->finaliseDimension($state);
                }
                LearnerSkillState::updateOrCreate(
                    ['user_id' => $session->user_id, 'skill_id' => $state->skill_id],
                    [
                        'ability' => $state->ability,
                        'ability_se' => $state->ability_se,
                        'cefr_level_id' => $state->result_cefr_level_id,
                        'confidence' => $state->confidence,
                        'attempt_count' => $state->items_administered,
                        'last_assessed_at' => now(),
                    ],
                );
            }

            $this->recomputeGlobal($session);
            $session->refresh();

            $level = $this->levelFor((float) $session->ability);
            $session->update([
                'status' => 'completed',
                'result_cefr_level_id' => $level?->id,
                'result_confidence' => $this->confidenceFor((float) $session->ability_se),
                'stop_reason' => $session->items_administered >= $session->max_items ? 'max_items' : 'precision_reached',
                'completed_at' => now(),
            ]);

            $profile = LearnerProfile::updateOrCreate(
                ['user_id' => $session->user_id],
                [
                    'language_id' => $session->language_id,
                    'current_cefr_level_id' => $level?->id,
                    'ability' => $session->ability,
                    'ability_se' => $session->ability_se,
                    'placement_status' => 'completed',
                    'placed_at' => now(),
                ],
            );

            // Without this the result is a number on a profile screen: the
            // curriculum reads active_course_version_id, and nothing was ever
            // writing it, so every learner started at lesson one of the first
            // book whatever the test said.
            $this->courses->assign($profile);

            return $session->fresh('skillStates');
        });
    }

    /** Per-skill breakdown for the result screen. */
    public function profile(PlacementSession $session): array
    {
        $session->loadMissing('skillStates.skill', 'skillStates.session');
        $levels = CefrLevel::orderBy('ordinal')->get();

        $skills = $session->skillStates->map(function (PlacementSkillState $s) use ($levels) {
            $level = $levels->firstWhere('id', $s->result_cefr_level_id) ?? $this->levelFor((float) $s->ability);

            return [
                'skill' => $s->skill?->code,
                'name' => $s->skill?->name,
                'cefr' => $level?->code,
                'ability' => round((float) $s->ability, 3),
                'standard_error' => round((float) $s->ability_se, 3),
                'confidence' => $s->confidence !== null ? round((float) $s->confidence, 3) : null,
                'items' => $s->items_administered,
                'complete' => (bool) $s->is_complete,
            ];
        })->values()->all();

        $overall = $this->levelFor((float) $session->ability);

        return [
            'overall' => [
                'cefr' => $overall?->code,
                'ability' => round((float) $session->ability, 3),
                'standard_error' => round((float) $session->ability_se, 3),
                'confidence' => $this->confidenceFor((float) $session->ability_se),
            ],
            'skills' => $skills,
            'items_administered' => $session->items_administered,
            'stop_reason' => $session->stop_reason,
        ];
    }

    // ------------------------------------------------------------- internals

    /** The dimension that still needs the most work. */
    private function pickDimension(PlacementSession $session): ?PlacementSkillState
    {
        $session->loadMissing('skillStates');
        if ($session->items_administered >= $session->max_items) {
            return null;
        }

        return $session->skillStates
            ->reject(fn (PlacementSkillState $s) => $s->is_complete)
            // Widest error first, but honour each dimension's minimum so every
            // skill gets a fair look before any is declared measured.
            ->sortByDesc(fn (PlacementSkillState $s) => $s->items_administered < $s->min_items
                ? 100 + (float) $s->ability_se
                : (float) $s->ability_se)
            ->first();
    }

    private function dimensionComplete(PlacementSkillState $s): bool
    {
        if ($s->items_administered < $s->min_items) {
            return false;
        }

        return $s->items_administered >= $s->max_items
            || (float) $s->ability_se <= (float) $s->target_se;
    }

    private function finaliseDimension(PlacementSkillState $s): void
    {
        $level = $this->levelFor((float) $s->ability);
        $s->update([
            'is_complete' => true,
            'result_cefr_level_id' => $level?->id,
            'confidence' => $this->confidenceFor((float) $s->ability_se),
        ]);
    }

    /**
     * Global ability is an information-weighted mean of the dimensions - a
     * precisely measured skill should count for more than a barely sampled one.
     */
    private function recomputeGlobal(PlacementSession $session): void
    {
        $states = PlacementSkillState::where('placement_session_id', $session->id)->get();
        $totalWeight = 0.0;
        $weighted = 0.0;

        foreach ($states as $s) {
            if ($s->items_administered === 0) {
                continue;
            }
            $w = 1 / max(0.04, (float) $s->ability_se ** 2);
            $weighted += $w * (float) $s->ability;
            $totalWeight += $w;
        }

        if ($totalWeight <= 0) {
            return;
        }

        $session->update([
            'ability' => round($weighted / $totalWeight, 4),
            'ability_se' => round(1 / sqrt($totalWeight), 4),
            'items_administered' => (int) $states->sum('items_administered'),
        ]);
    }

    private function levelFor(float $ability): ?CefrLevel
    {
        return CefrLevel::where('ability_min', '<=', $ability)
            ->where('ability_max', '>', $ability)
            ->orderBy('ordinal')
            ->first()
            ?? CefrLevel::orderBy('ordinal')->first();
    }

    private function confidenceFor(float $se): float
    {
        // SE 0.18 (our floor) reads as near-certain; SE 1.5 (the prior) as none.
        return round(max(0.0, min(1.0, 1 - (($se - 0.18) / 1.32))), 3);
    }
}
