<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\DailyProgress;
use App\Models\LearnerConcept;
use App\Models\LearnerProfile;
use App\Models\LearnerSkillState;
use App\Models\SkillSnapshot;
use App\Services\Learning\MasteryService;
use App\Services\Learning\RemediationService;
use App\Services\Learning\SpacedRepetitionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProgressController extends ApiController
{
    public function __construct(
        private MasteryService $mastery,
        private SpacedRepetitionService $srs,
        private RemediationService $remediation,
    ) {}

    /** Everything the home dashboard needs, in one call. */
    public function dashboard(Request $request)
    {
        $userId = $request->user()->id;
        $profile = LearnerProfile::with('cefrLevel')->where('user_id', $userId)->first();
        $settings = DB::table('user_settings')->where('user_id', $userId)->first();

        $today = DailyProgress::firstOrNew(['user_id' => $userId, 'date' => now()->toDateString()]);
        $goalMinutes = (int) ($settings->daily_target_minutes ?? 15);

        return $this->ok([
            'cefr_level' => $profile?->cefrLevel?->code,
            'ability' => (float) ($profile->ability ?? 0),
            'placement_status' => $profile?->placement_status,
            'streak_days' => (int) ($profile->streak_days ?? 0),
            'longest_streak_days' => (int) ($profile->longest_streak_days ?? 0),
            'xp' => (int) ($profile->xp ?? 0),
            'mastery_score' => $this->mastery->overall($userId),
            'total_study_minutes' => (int) ($profile->total_study_minutes ?? 0),
            'today' => [
                'study_seconds' => (int) $today->study_seconds,
                'goal_minutes' => $goalMinutes,
                'goal_progress' => $goalMinutes > 0
                    ? round(min(1.0, ($today->study_seconds / 60) / $goalMinutes), 3) : 0,
                'exercises_attempted' => (int) $today->exercises_attempted,
                'exercises_correct' => (int) $today->exercises_correct,
                'goal_met' => (bool) $today->goal_met,
            ],
            'due_reviews' => $this->srs->dueCount($userId),
            'vocabulary_learned' => LearnerConcept::where('user_id', $userId)
                ->where('mastery_score', '>=', MasteryService::COMPETENT)->count(),
            'concepts_tracked' => LearnerConcept::where('user_id', $userId)->count(),
            'skills' => $this->skillRadar($userId),
            'weak_areas' => $this->mastery->weakest($userId, 5)->map(fn ($c) => [
                'concept_id' => $c->concept_id,
                'label' => $c->concept?->label,
                'mastery_score' => (float) $c->mastery_score,
            ])->values(),
            'top_errors' => $this->remediation->unresolved($userId, 5)->map(fn ($e) => [
                'error_type' => $e->error_type,
                'occurrences' => $e->occurrence_count,
                'label' => $e->concept?->label,
            ])->values(),
        ]);
    }

    /** Per-skill proficiency for the radar chart. */
    public function skills(Request $request)
    {
        return $this->ok($this->skillRadar($request->user()->id));
    }

    /** Daily history for the progress charts. */
    public function history(Request $request)
    {
        $days = min(365, max(7, $request->integer('days', 30)));

        $rows = DailyProgress::where('user_id', $request->user()->id)
            ->where('date', '>=', now()->subDays($days)->toDateString())
            ->orderBy('date')
            ->get();

        return $this->ok($rows->map(fn (DailyProgress $d) => [
            'date' => $d->date->toDateString(),
            'study_seconds' => (int) $d->study_seconds,
            'exercises_attempted' => (int) $d->exercises_attempted,
            'exercises_correct' => (int) $d->exercises_correct,
            'reviews_completed' => (int) $d->reviews_completed,
            'concepts_mastered' => (int) $d->concepts_mastered,
            'xp_earned' => (int) $d->xp_earned,
            'goal_met' => (bool) $d->goal_met,
        ])->values(), ['days' => $days]);
    }

    /** Ability trend per skill over time. */
    public function trend(Request $request)
    {
        $days = min(365, max(14, $request->integer('days', 90)));

        $rows = SkillSnapshot::with('skill')
            ->where('user_id', $request->user()->id)
            ->where('snapshot_date', '>=', now()->subDays($days)->toDateString())
            ->orderBy('snapshot_date')
            ->get();

        return $this->ok(
            $rows->groupBy(fn (SkillSnapshot $s) => $s->skill?->code ?? 'unknown')
                ->map(fn ($group) => $group->map(fn (SkillSnapshot $s) => [
                    'date' => $s->snapshot_date->toDateString(),
                    'ability' => (float) $s->ability,
                    'mastery_score' => $s->mastery_score !== null ? (float) $s->mastery_score : null,
                ])->values())
        );
    }

    private function skillRadar(int $userId): array
    {
        $states = LearnerSkillState::with(['skill', 'cefrLevel'])
            ->where('user_id', $userId)->get()->keyBy('skill_id');

        return \App\Models\Skill::orderBy('position')->get()->map(function ($skill) use ($states) {
            $s = $states->get($skill->id);

            return [
                'code' => $skill->code,
                'name' => $skill->name,
                'cefr' => $s?->cefrLevel?->code,
                'ability' => $s ? round((float) $s->ability, 3) : null,
                'confidence' => $s ? round((float) $s->confidence, 3) : null,
                // 0..1 for radar rendering; ability runs roughly -6..6.
                'normalised' => $s ? round(max(0, min(1, ((float) $s->ability + 3) / 6)), 3) : 0.0,
                'assessed' => $s !== null && $s->attempt_count > 0,
            ];
        })->values()->all();
    }
}
