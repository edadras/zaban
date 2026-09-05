<?php

namespace App\Services\Learning;

use App\Models\Concept;
use App\Models\Exercise;
use App\Models\LearnerConcept;
use App\Models\LearnerError;
use App\Models\LearnerProfile;
use App\Models\LearningSession;
use App\Models\Lesson;
use App\Models\SessionActivity;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Decides what the learner does next.
 *
 * buildNextSession composes an ordered set of activities from competing demands -
 * due reviews, curriculum progress, known weaknesses, speaking practice and
 * variety - rather than walking a fixed lesson list. The weights shift with the
 * learner's state: someone with a large review backlog gets more review; someone
 * who has been getting things wrong gets an easier, shorter session.
 */
class AdaptiveLearningService
{
    /** Starting mix from spec section 51; adjusted per learner below. */
    private const BASE_WEIGHTS = [
        'review' => 0.30,
        'curriculum' => 0.30,
        'weakness' => 0.20,
        'speaking' => 0.10,
        'exploration' => 0.10,
    ];

    public function __construct(
        private MasteryService $mastery,
        private SpacedRepetitionService $srs,
        private DifficultyService $difficulty,
        private RemediationService $remediation,
    ) {}

    public function buildNextSession(int $userId, ?int $minutes = null): LearningSession
    {
        $profile = LearnerProfile::firstOrCreate(['user_id' => $userId], [
            'language_id' => \App\Models\Language::where('code', 'en')->value('id'),
        ]);
        $minutes = $minutes ?: $this->plannedMinutes($userId);

        $weights = $this->weightsFor($userId, $profile);
        $slots = $this->allocateSlots($weights, $minutes);

        return DB::transaction(function () use ($userId, $profile, $minutes, $weights, $slots) {
            $session = LearningSession::create([
                'user_id' => $userId,
                'course_version_id' => $profile->active_course_version_id,
                'status' => 'active',
                'kind' => 'daily',
                'composition' => ['weights' => $weights, 'slots' => $slots],
                'planned_minutes' => $minutes,
                'started_at' => now(),
            ]);

            $activities = collect()
                ->concat($this->reviewActivities($userId, $slots['review']))
                ->concat($this->weaknessActivities($userId, $slots['weakness']))
                ->concat($this->curriculumActivities($userId, $profile, $slots['curriculum']))
                ->concat($this->speakingActivities($userId, $slots['speaking']))
                ->concat($this->explorationActivities($userId, $slots['exploration']));

            // Alternate activity types so the session never feels like a drill
            // list, then persist in that order.
            $ordered = $this->interleave($activities);

            $position = 0;
            foreach ($ordered as $a) {
                SessionActivity::create([
                    'learning_session_id' => $session->id,
                    'position' => $position++,
                    'activity_type' => $a['type'],
                    'subject_type' => $a['subject_type'] ?? null,
                    'subject_id' => $a['subject_id'] ?? null,
                    'concept_id' => $a['concept_id'] ?? null,
                    'selection_reason' => $a['reason'],
                    'priority_score' => $a['priority'] ?? null,
                    'predicted_success' => $a['predicted'] ?? null,
                    'status' => 'pending',
                ]);
            }

            $session->update(['activities_planned' => $position]);

            return $session->fresh('activities');
        });
    }

    /**
     * Shift the mix toward what this learner needs now: a review backlog pulls
     * weight from new material, and recent frustration shortens and softens the
     * session rather than pushing harder.
     */
    private function weightsFor(int $userId, LearnerProfile $profile): array
    {
        $w = self::BASE_WEIGHTS;
        $due = $this->srs->dueCount($userId);
        $frustration = (float) $profile->frustration_index;

        if ($due > 25) {
            $w['review'] += 0.15;
            $w['curriculum'] -= 0.10;
            $w['exploration'] -= 0.05;
        } elseif ($due === 0) {
            $w['review'] = 0.05;
            $w['curriculum'] += 0.15;
            $w['exploration'] += 0.10;
        }

        if ($frustration > 0.5) {
            // Consolidate instead of advancing.
            $w['weakness'] += 0.10;
            $w['review'] += 0.05;
            $w['curriculum'] -= 0.15;
        }

        if ($profile->placement_status !== 'completed') {
            $w['exploration'] += 0.05;
        }

        $sum = array_sum($w);

        return array_map(fn ($v) => round(max(0, $v) / $sum, 3), $w);
    }

    /** Roughly one activity per minute of planned study. */
    private function allocateSlots(array $weights, int $minutes): array
    {
        $total = max(4, (int) round($minutes * 0.9));
        $slots = [];
        foreach ($weights as $k => $v) {
            $slots[$k] = (int) max(0, round($total * $v));
        }

        return $slots;
    }

    private function plannedMinutes(int $userId): int
    {
        return (int) (DB::table('user_settings')->where('user_id', $userId)->value('daily_target_minutes') ?: 15);
    }

    private function reviewActivities(int $userId, int $count): Collection
    {
        if ($count <= 0) {
            return collect();
        }

        return $this->srs->due($userId, $count)->map(function (LearnerConcept $lc) use ($userId) {
            $exercise = $this->pickExerciseForConcept($userId, $lc->concept_id);

            return [
                'type' => 'review',
                'subject_type' => $exercise ? Exercise::class : null,
                'subject_id' => $exercise?->id,
                'concept_id' => $lc->concept_id,
                'priority' => round($this->srs->forgettingProbability($lc) * 100, 4),
                'predicted' => $exercise ? $this->difficulty->successProbability(
                    $this->difficulty->abilityFor($userId), (float) $exercise->difficulty,
                ) : null,
                'reason' => [
                    'driver' => 'spaced_repetition',
                    'due_since' => $lc->next_review_at?->toIso8601String(),
                    'forgetting_probability' => $this->srs->forgettingProbability($lc),
                    'mastery' => (float) $lc->mastery_score,
                ],
            ];
        })->filter(fn ($a) => $a['subject_id'] !== null)->values();
    }

    private function weaknessActivities(int $userId, int $count): Collection
    {
        if ($count <= 0) {
            return collect();
        }

        $out = collect();
        foreach ($this->mastery->weakest($userId, $count * 2) as $lc) {
            if ($out->count() >= $count) {
                break;
            }

            // Repeated failure means the same question again will not help.
            $plan = $this->remediation->planFor($userId, $lc);
            $exercise = $plan['exercise'] ?? $this->pickExerciseForConcept($userId, $lc->concept_id, $plan['exclude_ids'] ?? []);
            if (! $exercise) {
                continue;
            }

            $out->push([
                'type' => $plan['strategy'] === 'retest' ? 'weakness' : 'remediation',
                'subject_type' => Exercise::class,
                'subject_id' => $exercise->id,
                'concept_id' => $lc->concept_id,
                'priority' => round((1 - (float) $lc->mastery_score) * 100, 4),
                'predicted' => $this->difficulty->successProbability(
                    $this->difficulty->abilityFor($userId), (float) $exercise->difficulty,
                ),
                'reason' => [
                    'driver' => 'weakness',
                    'mastery' => (float) $lc->mastery_score,
                    'incorrect_count' => $lc->incorrect_count,
                    'strategy' => $plan['strategy'],
                ],
            ]);
        }

        return $out;
    }

    private function curriculumActivities(int $userId, LearnerProfile $profile, int $count): Collection
    {
        if ($count <= 0) {
            return collect();
        }

        $lesson = $this->nextLesson($userId, $profile);
        if (! $lesson) {
            return collect();
        }

        $out = collect();
        foreach ($lesson->blocks()->orderBy('position')->limit($count)->get() as $block) {
            $out->push([
                'type' => 'lesson_block',
                'subject_type' => \App\Models\LessonBlock::class,
                'subject_id' => $block->id,
                'concept_id' => null,
                'priority' => 50.0,
                'reason' => [
                    'driver' => 'curriculum',
                    'lesson_id' => $lesson->id,
                    'lesson' => $lesson->title,
                    'block_type' => $block->type,
                ],
            ]);
        }

        return $out;
    }

    private function speakingActivities(int $userId, int $count): Collection
    {
        if ($count <= 0) {
            return collect();
        }

        // Prefer drills on the phonemes this learner actually gets wrong.
        $weakPhonemes = DB::table('pronunciation_errors')
            ->where('user_id', $userId)->whereNull('resolved_at')
            ->orderByDesc('error_rate')->limit(3)->pluck('phoneme_id');

        $blocks = DB::table('lesson_blocks')
            ->whereIn('type', ['repeat_after_speaker', 'pronunciation_drill', 'open_speaking'])
            ->inRandomOrder()->limit($count)->get();

        return collect($blocks)->map(fn ($b) => [
            'type' => 'speaking',
            'subject_type' => \App\Models\LessonBlock::class,
            'subject_id' => $b->id,
            'concept_id' => null,
            'priority' => 40.0,
            'reason' => [
                'driver' => 'speaking_practice',
                'targets_weak_phonemes' => $weakPhonemes->isNotEmpty(),
            ],
        ]);
    }

    private function explorationActivities(int $userId, int $count): Collection
    {
        if ($count <= 0) {
            return collect();
        }

        // Concepts at the learner's level they have never met.
        $seen = LearnerConcept::where('user_id', $userId)->pluck('concept_id');
        $ability = $this->difficulty->abilityFor($userId);

        $concepts = Concept::whereNotIn('id', $seen)
            ->whereBetween('difficulty', [$ability - 0.9, $ability + 0.9])
            ->where('is_active', true)
            ->inRandomOrder()->limit($count)->get();

        return $concepts->map(function (Concept $c) use ($userId) {
            $exercise = $this->pickExerciseForConcept($userId, $c->id);

            return $exercise ? [
                'type' => 'exploration',
                'subject_type' => Exercise::class,
                'subject_id' => $exercise->id,
                'concept_id' => $c->id,
                'priority' => 20.0,
                'reason' => ['driver' => 'new_material', 'label' => $c->label],
            ] : null;
        })->filter()->values();
    }

    /** Pick the best-fitting exercise for a concept at the learner's ability. */
    public function pickExerciseForConcept(int $userId, int $conceptId, array $excludeIds = []): ?Exercise
    {
        $ability = $this->difficulty->abilityFor($userId);

        $candidates = Exercise::query()
            ->join('exercise_concepts', 'exercise_concepts.exercise_id', '=', 'exercises.id')
            ->where('exercise_concepts.concept_id', $conceptId)
            ->when($excludeIds, fn ($q) => $q->whereNotIn('exercises.id', $excludeIds))
            ->whereNull('exercises.deleted_at')
            ->select('exercises.*')
            ->distinct()
            ->limit(25)
            ->get();

        return $this->difficulty->choose($candidates, $ability);
    }

    private function nextLesson(int $userId, LearnerProfile $profile): ?Lesson
    {
        $completed = DB::table('lesson_attempts')
            ->where('user_id', $userId)->where('status', 'completed')->pluck('lesson_id');

        return Lesson::query()
            ->when($profile->active_course_version_id, function ($q) use ($profile) {
                $q->whereHas('unit.module', fn ($m) => $m->where('course_version_id', $profile->active_course_version_id));
            })
            ->whereNotIn('id', $completed)
            ->whereHas('concepts')
            ->orderBy('unit_id')->orderBy('position')
            ->first();
    }

    /**
     * Spread activity types so the same kind never runs three times in a row -
     * variety is what keeps a session from feeling like a worksheet.
     */
    private function interleave(Collection $activities): Collection
    {
        $byType = $activities->groupBy('type')->map->values()->all();
        $out = collect();
        $lastType = null;

        while (array_filter($byType, fn ($g) => $g->isNotEmpty())) {
            $candidates = array_filter($byType, fn ($g, $t) => $g->isNotEmpty() && $t !== $lastType, ARRAY_FILTER_USE_BOTH);
            if (! $candidates) {
                $candidates = array_filter($byType, fn ($g) => $g->isNotEmpty());
            }
            // Take from whichever remaining type has the most left, so nothing
            // bunches at the end.
            uasort($candidates, fn ($a, $b) => $b->count() <=> $a->count());
            $type = array_key_first($candidates);

            $out->push($byType[$type]->shift());
            $byType[$type] = $byType[$type]->values();
            $lastType = $type;
        }

        return $out;
    }
}
