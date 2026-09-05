<?php

namespace App\Services\Exam;

use App\Models\ExamAttempt;
use App\Models\ExamScore;
use App\Models\ExamSectionAttempt;
use App\Models\ExerciseAttempt;
use App\Models\LearnerError;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What the learner should actually do differently next time.
 *
 * A band on its own changes nothing. This turns a sitting into the four things
 * that do: where the time went, which question types leak marks, which mistakes
 * keep coming back, and whether any of it is moving (spec 32).
 */
class ExamAnalyticsService
{
    /** Below this share of the allowed time, a section was rushed rather than finished early. */
    private const RUSHED_RATIO = 0.5;

    public function __construct(private ExamService $exams) {}

    /**
     * Per-section pacing, stored on exam_attempts.time_management.
     *
     * @return array<string, mixed>
     */
    public function timeManagement(ExamAttempt $attempt): array
    {
        $attempt->loadMissing('sectionAttempts.section', 'examType');

        $sections = [];
        $rushed = [];
        $overran = [];
        $allowedTotal = 0;
        $usedTotal = 0;

        foreach ($attempt->sectionAttempts->sortBy(fn (ExamSectionAttempt $sa) => $sa->section->position) as $sectionAttempt) {
            $section = $sectionAttempt->section;
            $allowed = (int) $section->duration_minutes * 60;
            $used = (int) $sectionAttempt->duration_seconds;

            $responses = $this->exams->responsesFor($attempt, $sectionAttempt);
            $available = $this->exams->sectionTasks($attempt, $section)->count();
            $taskSeconds = $responses
                ->map(fn (ExamScore $r) => $r->evidence['seconds_used'] ?? null)
                ->filter(fn ($v) => is_numeric($v))
                ->map(fn ($v) => (int) $v);

            $ratio = $allowed > 0 ? round($used / $allowed, 3) : null;

            if ($sectionAttempt->ran_out_of_time) {
                $overran[] = $section->code;
            } elseif ($responses->isNotEmpty() && $ratio !== null && $ratio < self::RUSHED_RATIO) {
                $rushed[] = $section->code;
            }

            $allowedTotal += $allowed;
            $usedTotal += $used;

            $sections[$section->code] = [
                'section' => $section->code,
                'name' => $section->name,
                'allowed_seconds' => $allowed,
                'used_seconds' => $used,
                'used_ratio' => $ratio,
                'ran_out_of_time' => (bool) $sectionAttempt->ran_out_of_time,
                'tasks_available' => $available,
                'tasks_submitted' => $responses->count(),
                'tasks_unanswered' => max(0, $available - $responses->count()),
                'median_task_seconds' => $taskSeconds->isNotEmpty() ? (int) round($taskSeconds->median()) : null,
                'slowest_task_seconds' => $taskSeconds->max(),
            ];
        }

        return [
            'allowed_seconds' => $allowedTotal,
            'used_seconds' => $usedTotal,
            'sections' => $sections,
            'flags' => [
                'ran_out_of_time' => $overran,
                'finished_suspiciously_fast' => $rushed,
            ],
        ];
    }

    /**
     * Accuracy per exam question type, which is the unit exam candidates actually
     * train in ("I lose marks on matching headings", not "I lose marks in reading").
     *
     * @return array<int, array<string, mixed>>
     */
    public function questionTypePerformance(ExamAttempt $attempt): array
    {
        return $this->examAttempts($attempt)
            ->groupBy(fn (ExerciseAttempt $a) => $a->feedback['exam_task_type'] ?? 'unknown')
            ->map(function (Collection $group, string $type) {
                $marked = $group->count();
                $correct = $group->where('is_correct', true)->count();

                return [
                    'task_type' => $type,
                    'items' => $marked,
                    'correct' => $correct,
                    'accuracy' => $marked ? round($correct / $marked, 3) : null,
                    'mean_score' => $marked ? round((float) $group->avg('score'), 3) : null,
                ];
            })
            ->sortBy('accuracy')
            ->values()
            ->all();
    }

    /**
     * The mistakes worth reviewing: wrong answers from this sitting, plus the
     * error records they created, so the learner sees the pattern and the
     * curriculum sees the same rows.
     *
     * @return array<string, mixed>
     */
    public function commonMistakes(ExamAttempt $attempt, int $limit = 20): array
    {
        $wrong = $this->examAttempts($attempt)
            ->where('is_correct', false)
            ->map(fn (ExerciseAttempt $a) => [
                'task_type' => $a->feedback['exam_task_type'] ?? null,
                'expected' => $a->feedback['expected'] ?? null,
                'given' => $this->givenOf($a),
                'exercise_id' => $a->exercise_id,
            ])
            ->values()
            ->take($limit)
            ->all();

        // Errors the exam wrote into the shared log during this sitting; these are
        // the ones that will resurface in daily practice.
        $recorded = LearnerError::query()
            ->where('user_id', $attempt->user_id)
            ->where('last_seen_at', '>=', $attempt->started_at)
            ->orderByDesc('severity')
            ->orderByDesc('occurrence_count')
            ->limit($limit)
            ->get()
            ->map(fn (LearnerError $e) => [
                'error_type' => $e->error_type,
                'subtype' => $e->error_subtype,
                'input' => $e->input,
                'expected' => $e->expected,
                'occurrences' => (int) $e->occurrence_count,
                'severity' => (int) $e->severity,
            ])->all();

        $byType = collect($wrong)
            ->groupBy(fn ($m) => $m['task_type'] ?? 'unknown')
            ->map->count()
            ->sortDesc()
            ->all();

        return [
            'missed_items' => $wrong,
            'recorded_errors' => $recorded,
            'by_task_type' => $byType,
        ];
    }

    /**
     * Every completed sitting for this exam, oldest first, so the client can draw
     * a line rather than a single dot.
     *
     * @return array<string, mixed>
     */
    public function progress(int $userId, ?int $examTypeId = null): array
    {
        $attempts = ExamAttempt::with(['examType', 'sectionAttempts.section.skill'])
            ->where('user_id', $userId)
            ->where('status', 'completed')
            ->when($examTypeId, fn ($q) => $q->where('exam_type_id', $examTypeId))
            ->orderBy('completed_at')
            ->get();

        $points = $attempts->map(function (ExamAttempt $attempt) {
            $skills = [];
            foreach ($attempt->sectionAttempts as $sectionAttempt) {
                $skill = $sectionAttempt->section->skill?->code ?? $sectionAttempt->section->code;
                $skills[$skill] = $sectionAttempt->estimated_score !== null
                    ? (float) $sectionAttempt->estimated_score
                    : null;
            }

            return [
                'exam_attempt_id' => $attempt->id,
                'exam_type' => $attempt->examType?->code,
                'mode' => $attempt->mode,
                'completed_at' => $attempt->completed_at?->toIso8601String(),
                'estimated_score' => $attempt->estimated_score !== null ? (float) $attempt->estimated_score : null,
                'cefr' => $this->levelCode($attempt),
                'is_ai_estimated' => (bool) $attempt->is_ai_estimated,
                'skills' => $skills,
                'duration_seconds' => (int) $attempt->duration_seconds,
            ];
        })->values();

        $scored = $points->filter(fn ($p) => $p['estimated_score'] !== null)->values();
        $first = $scored->first();
        $last = $scored->last();

        return [
            'points' => $points->all(),
            'attempts' => $points->count(),
            'latest' => $last,
            'change' => $scored->count() > 1
                ? round($last['estimated_score'] - $first['estimated_score'], 2)
                : null,
            'best' => $scored->max('estimated_score'),
            'weakest_skills' => $this->weakestSkills($scored),
        ];
    }

    // ------------------------------------------------------------ internals

    /** @return Collection<int, ExerciseAttempt> */
    private function examAttempts(ExamAttempt $attempt): Collection
    {
        return ExerciseAttempt::query()
            ->where('user_id', $attempt->user_id)
            ->where('feedback->exam_attempt_id', $attempt->id)
            ->orderBy('id')
            ->get();
    }

    private function givenOf(ExerciseAttempt $a): ?string
    {
        $detail = $a->feedback['detail'] ?? [];
        if (($detail['kind'] ?? null) === 'text') {
            return collect($detail['blanks'] ?? [])->pluck('given')->filter()->implode(' | ') ?: null;
        }
        $response = $a->response ?? [];

        return is_array($response) ? (json_encode($response, JSON_UNESCAPED_UNICODE) ?: null) : (string) $response;
    }

    private function levelCode(ExamAttempt $attempt): ?string
    {
        if (! $attempt->estimated_cefr_level_id) {
            return null;
        }

        return DB::table('cefr_levels')->where('id', $attempt->estimated_cefr_level_id)->value('code');
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $points
     * @return array<int, array<string, mixed>>
     */
    private function weakestSkills(Collection $points): array
    {
        $latest = $points->last();
        if (! $latest) {
            return [];
        }

        return collect($latest['skills'])
            ->filter(fn ($v) => $v !== null)
            ->sort()
            ->take(2)
            ->map(fn ($score, $skill) => ['skill' => $skill, 'estimated_score' => $score])
            ->values()
            ->all();
    }
}
