<?php

namespace App\Services\Exam;

use App\Models\ExamAttempt;
use App\Models\ExamScore;
use App\Models\ExamSection;
use App\Models\ExamSectionAttempt;
use App\Models\ExamTask;
use App\Models\ExamType;
use App\Models\Exercise;
use App\Models\ExerciseAttempt;
use App\Models\SpeechAttempt;
use App\Services\Learning\DifficultyService;
use App\Services\Learning\RemediationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Runs an exam sitting: start, serve tasks in order under the section clock,
 * record responses, close the attempt.
 *
 * Exam preparation is deliberately separate from the CEFR curriculum, but it is
 * not a separate learner. Task selection in practice mode targets the ability
 * already estimated by placement and daily practice, and every mistake made here
 * is written back into learner_errors so the curriculum picks it up (spec 32).
 *
 * Storage note: the schema has no exam_responses table, so a submission is kept
 * as an exam_scores row under the reserved criterion "_response:<task id>",
 * which the (exam_attempt_id, criterion) index makes cheap to look up. Rubric
 * criteria rows sit alongside it under their own codes; anything starting with
 * an underscore is bookkeeping, not a score.
 */
class ExamService
{
    public const MODE_PRACTICE = 'practice';
    public const MODE_MOCK = 'mock';
    public const MODE_SECTION = 'section';

    public const MODES = [self::MODE_PRACTICE, self::MODE_MOCK, self::MODE_SECTION];

    /** Reserved criterion prefix holding a submission rather than a score. */
    public const RESPONSE_CRITERION = '_response:';

    /** Tasks only reach a learner once they have cleared content review. */
    /** @deprecated Kept as an alias; Exercise owns the contract. */
    public const SERVABLE_STATUSES = Exercise::SERVABLE_STATUSES;

    /** A section this sitting never covered; scoring projects it from the curriculum. */
    public const STATUS_NOT_ATTEMPTED = 'not_attempted';

    /** A section whose score came from the curriculum rather than from this sitting. */
    public const STATUS_PROJECTED = 'projected';

    /** A practice run is a short targeted set, not the whole paper. */
    private const PRACTICE_TASKS_PER_SECTION = 3;

    /** Clock tolerance for network latency on a submission that lands on the buzzer. */
    private const GRACE_SECONDS = 5;

    public function __construct(
        private ObjectiveGrader $grader,
        private DifficultyService $difficulty,
        private RemediationService $remediation,
    ) {}

    // ----------------------------------------------------------- lifecycle

    public function start(int $userId, ExamType $examType, string $mode = self::MODE_PRACTICE, ?int $examSectionId = null): ExamAttempt
    {
        if (! in_array($mode, self::MODES, true)) {
            throw ExamException::invalidMode($mode);
        }

        $sections = $examType->sections()->orderBy('position')->get();
        if ($sections->isEmpty()) {
            throw ExamException::noContent($examType->name);
        }

        if ($mode === self::MODE_SECTION && (! $examSectionId || ! $sections->contains('id', $examSectionId))) {
            throw ExamException::sectionMismatch();
        }

        // Sections outside the chosen mode still get a row: scoring fills them
        // from the learner's existing level so a single-section rehearsal can
        // still show a whole-exam picture, clearly marked as projected.
        $inScope = $mode === self::MODE_SECTION
            ? [$examSectionId]
            : $sections->pluck('id')->all();

        // An unfinished sitting of the same shape is resumed rather than
        // duplicated; otherwise a dropped connection quietly loses the learner's
        // work. A different mode - a full mock after a reading rehearsal - is a
        // different sitting and starts fresh.
        $open = ExamAttempt::where('user_id', $userId)
            ->where('exam_type_id', $examType->id)
            ->where('status', 'in_progress')
            ->where('mode', $mode)
            ->latest('id')->first();

        if ($open) {
            $open->load('sectionAttempts.section');
            $sameScope = $mode !== self::MODE_SECTION || $open->sectionAttempts
                ->where('exam_section_id', $examSectionId)
                ->where('status', '!=', self::STATUS_NOT_ATTEMPTED)
                ->isNotEmpty();

            if ($sameScope) {
                return $open;
            }
        }

        return DB::transaction(function () use ($userId, $examType, $mode, $sections, $inScope) {
            $attempt = ExamAttempt::create([
                'user_id' => $userId,
                'exam_type_id' => $examType->id,
                'mode' => $mode,
                'status' => 'in_progress',
                // Stays true until finish() proves every section was marked
                // deterministically from an answer key.
                'is_ai_estimated' => true,
                'started_at' => now(),
            ]);

            foreach ($sections as $section) {
                ExamSectionAttempt::create([
                    'exam_attempt_id' => $attempt->id,
                    'exam_section_id' => $section->id,
                    'status' => in_array($section->id, $inScope, true) ? 'pending' : self::STATUS_NOT_ATTEMPTED,
                ]);
            }

            return $attempt->load('sectionAttempts.section');
        });
    }

    /**
     * The next thing the learner should see, or null when the sitting is over.
     *
     * @return array<string, mixed>|null
     */
    public function nextTask(ExamAttempt $attempt): ?array
    {
        $this->assertInProgress($attempt);
        $this->closeOverdueSections($attempt);

        $sectionAttempt = $this->currentSection($attempt);
        if (! $sectionAttempt) {
            return null;
        }

        $task = $this->pendingTasks($attempt, $sectionAttempt)->first();

        if (! $task) {
            // Nothing left in this section; close it and look at the next one.
            $this->closeSection($sectionAttempt, ranOutOfTime: false);

            return $this->nextTask($attempt->fresh('sectionAttempts'));
        }

        return $this->presentTask($attempt, $sectionAttempt, $task);
    }

    /**
     * Record one task's response. Objective tasks are marked here and now;
     * productive ones are stored and scored when the attempt is finished.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function submitResponse(ExamAttempt $attempt, ExamTask $task, array $payload, ?int $secondsUsed = null): array
    {
        $this->assertInProgress($attempt);
        $this->closeOverdueSections($attempt);

        $sectionAttempt = $this->sectionAttemptForTask($attempt, $task);
        $section = $sectionAttempt->section;

        if ($sectionAttempt->status !== 'in_progress') {
            throw ExamException::sectionExpired($section->name);
        }
        if ($this->enforcesTiming($attempt) && $this->remainingSeconds($sectionAttempt) <= -self::GRACE_SECONDS) {
            $this->closeSection($sectionAttempt, ranOutOfTime: true);
            throw ExamException::sectionExpired($section->name);
        }

        $scoring = SectionScoring::for($section);

        return DB::transaction(function () use ($attempt, $task, $payload, $secondsUsed, $sectionAttempt, $scoring) {
            $record = $scoring->isObjective()
                ? $this->recordObjective($attempt, $sectionAttempt, $task, $payload)
                : $this->recordProductive($attempt, $sectionAttempt, $task, $payload);

            $record['seconds_used'] = $secondsUsed;
            $record['submitted_at'] = now()->toIso8601String();
            $record['exam_task_id'] = $task->id;
            $record['exam_section_attempt_id'] = $sectionAttempt->id;
            $record['exam_task_type'] = $task->taskType?->code;

            $this->writeResponseRow($attempt, $sectionAttempt, $task, $record);

            return $record;
        });
    }

    /** Close every open section and hand the attempt to scoring. */
    public function finish(ExamAttempt $attempt, ScoringService $scoring): ExamAttempt
    {
        if ($attempt->status !== 'in_progress') {
            return $attempt->fresh(['sectionAttempts.section', 'scores']);
        }

        foreach ($attempt->sectionAttempts as $sectionAttempt) {
            if ($sectionAttempt->status === 'in_progress') {
                $this->closeSection($sectionAttempt, ranOutOfTime: $this->remainingSeconds($sectionAttempt) <= 0);
            }
        }

        $attempt->update([
            'status' => 'completed',
            'completed_at' => now(),
            'duration_seconds' => max(0, now()->diffInSeconds($attempt->started_at, absolute: true)),
        ]);

        return $scoring->scoreAttempt($attempt->fresh(['sectionAttempts.section', 'scores']));
    }

    public function abandon(ExamAttempt $attempt): ExamAttempt
    {
        $attempt->update(['status' => 'abandoned', 'completed_at' => now()]);

        return $attempt->fresh();
    }

    // -------------------------------------------------------------- timing

    /** Practice records the clock but does not slam the door; mock and section rehearsal do. */
    public function enforcesTiming(ExamAttempt $attempt): bool
    {
        return $attempt->mode !== self::MODE_PRACTICE;
    }

    public function sectionDeadline(ExamSectionAttempt $sectionAttempt): ?Carbon
    {
        if (! $sectionAttempt->started_at) {
            return null;
        }

        return $sectionAttempt->started_at->copy()->addMinutes((int) $sectionAttempt->section->duration_minutes);
    }

    public function remainingSeconds(ExamSectionAttempt $sectionAttempt): int
    {
        $deadline = $this->sectionDeadline($sectionAttempt);
        if (! $deadline) {
            return (int) $sectionAttempt->section->duration_minutes * 60;
        }

        return (int) round(now()->diffInSeconds($deadline, absolute: false));
    }

    /** Mark any section whose clock has run out, whether or not the learner came back. */
    public function closeOverdueSections(ExamAttempt $attempt): void
    {
        foreach ($attempt->sectionAttempts as $sectionAttempt) {
            if ($sectionAttempt->status !== 'in_progress') {
                continue;
            }
            if ($this->remainingSeconds($sectionAttempt) > 0) {
                continue;
            }
            // Practice keeps the section open but still records the overrun, so
            // the time-management report is honest either way.
            if ($this->enforcesTiming($attempt)) {
                $this->closeSection($sectionAttempt, ranOutOfTime: true);
            } else {
                $sectionAttempt->update(['ran_out_of_time' => true]);
            }
        }

        $attempt->load('sectionAttempts.section');
    }

    // -------------------------------------------------------------- lookup

    public function currentSection(ExamAttempt $attempt): ?ExamSectionAttempt
    {
        $ordered = $attempt->sectionAttempts->sortBy(fn (ExamSectionAttempt $sa) => $sa->section->position)->values();

        $open = $ordered->firstWhere('status', 'in_progress');
        if ($open) {
            return $open;
        }

        $next = $ordered->firstWhere('status', 'pending');
        if (! $next) {
            return null;
        }

        $next->update(['status' => 'in_progress', 'started_at' => now()]);

        return $next->fresh('section');
    }

    /**
     * Tasks in this section the learner has not yet submitted, in the order they
     * should be served.
     *
     * @return Collection<int, ExamTask>
     */
    public function pendingTasks(ExamAttempt $attempt, ExamSectionAttempt $sectionAttempt): Collection
    {
        $tasks = $this->sectionTasks($attempt, $sectionAttempt->section);
        $submitted = $this->submittedTaskIds($attempt);

        return $tasks->reject(fn (ExamTask $t) => in_array($t->id, $submitted, true))->values();
    }

    /** @return Collection<int, ExamTask> */
    public function sectionTasks(ExamAttempt $attempt, ExamSection $section): Collection
    {
        $tasks = ExamTask::query()
            ->join('exam_task_types', 'exam_task_types.id', '=', 'exam_tasks.exam_task_type_id')
            ->where('exam_task_types.exam_section_id', $section->id)
            ->whereIn('exam_tasks.status', self::SERVABLE_STATUSES)
            ->orderBy('exam_task_types.id')
            ->orderBy('exam_tasks.position')
            ->orderBy('exam_tasks.id')
            ->select('exam_tasks.*')
            ->with('taskType')
            ->get();

        if ($attempt->mode !== self::MODE_PRACTICE || $tasks->count() <= self::PRACTICE_TASKS_PER_SECTION) {
            return $tasks;
        }

        // Practice is where the existing ability estimate earns its keep: serve
        // the few tasks pitched closest to where this learner already is rather
        // than the whole paper from item one.
        $ability = $this->difficulty->abilityFor($attempt->user_id, $section->skill_id);
        $difficulties = $this->taskDifficulties($tasks->pluck('id')->all());

        return $tasks
            ->sortBy(fn (ExamTask $t) => abs(($difficulties[$t->id] ?? $ability) - $ability))
            ->take(self::PRACTICE_TASKS_PER_SECTION)
            ->sortBy(fn (ExamTask $t) => [$t->exam_task_type_id, $t->position, $t->id])
            ->values();
    }

    /** @return int[] */
    public function submittedTaskIds(ExamAttempt $attempt): array
    {
        return ExamScore::where('exam_attempt_id', $attempt->id)
            ->pluck('criterion')
            // Filtered in PHP rather than with LIKE: the underscore prefix is a
            // wildcard in SQL, and an attempt has at most a few dozen rows.
            ->filter(fn (string $c) => self::isResponseCriterion($c))
            ->map(fn (string $c) => (int) substr($c, strlen(self::RESPONSE_CRITERION)))
            ->values()->all();
    }

    public static function isResponseCriterion(string $criterion): bool
    {
        return str_starts_with($criterion, self::RESPONSE_CRITERION);
    }

    /** @return Collection<int, ExamScore> the submission rows for one section */
    public function responsesFor(ExamAttempt $attempt, ExamSectionAttempt $sectionAttempt): Collection
    {
        return ExamScore::where('exam_attempt_id', $attempt->id)
            ->where('exam_section_attempt_id', $sectionAttempt->id)
            ->orderBy('id')
            ->get()
            ->filter(fn (ExamScore $s) => self::isResponseCriterion($s->criterion))
            ->values();
    }

    public function responseFor(ExamAttempt $attempt, int $taskId): ?ExamScore
    {
        return ExamScore::where('exam_attempt_id', $attempt->id)
            ->where('criterion', self::RESPONSE_CRITERION.$taskId)
            ->first();
    }

    // ------------------------------------------------------------ internals

    private function assertInProgress(ExamAttempt $attempt): void
    {
        if ($attempt->status !== 'in_progress') {
            throw ExamException::notInProgress();
        }

        $total = (int) ($attempt->examType->total_minutes ?? 0);
        if ($this->enforcesTiming($attempt) && $attempt->mode === self::MODE_MOCK && $total > 0) {
            if ($attempt->started_at->copy()->addMinutes($total)->isPast()) {
                throw ExamException::attemptExpired();
            }
        }
    }

    private function sectionAttemptForTask(ExamAttempt $attempt, ExamTask $task): ExamSectionAttempt
    {
        $sectionId = $task->taskType?->exam_section_id;
        $sectionAttempt = $attempt->sectionAttempts->firstWhere('exam_section_id', $sectionId);

        if (! $sectionAttempt) {
            throw ExamException::taskNotAvailable();
        }

        return $sectionAttempt->load('section');
    }

    /** @return array<string, mixed> */
    private function presentTask(ExamAttempt $attempt, ExamSectionAttempt $sectionAttempt, ExamTask $task): array
    {
        $section = $sectionAttempt->section;
        $scoring = SectionScoring::for($section);
        $remaining = $this->pendingTasks($attempt, $sectionAttempt);

        return [
            'attempt' => $attempt,
            'section_attempt' => $sectionAttempt,
            'section' => $section,
            'task' => $task,
            'task_type' => $task->taskType,
            'exercises' => $scoring->isObjective() ? $this->taskExercises($task) : collect(),
            'kind' => $scoring->mode(),
            'timing' => [
                'section_allowed_seconds' => (int) $section->duration_minutes * 60,
                'section_remaining_seconds' => max(0, $this->remainingSeconds($sectionAttempt)),
                'section_deadline' => $this->sectionDeadline($sectionAttempt)?->toIso8601String(),
                'task_limit_seconds' => $task->time_limit_seconds ? (int) $task->time_limit_seconds : null,
                'enforced' => $this->enforcesTiming($attempt),
            ],
            'position' => [
                'tasks_remaining_in_section' => $remaining->count(),
                'section_position' => (int) $section->position,
            ],
        ];
    }

    /** @return Collection<int, Exercise> */
    public function taskExercises(ExamTask $task): Collection
    {
        return Exercise::query()
            ->join('exam_task_exercise', 'exam_task_exercise.exercise_id', '=', 'exercises.id')
            ->where('exam_task_exercise.exam_task_id', $task->id)
            ->whereNull('exercises.deleted_at')
            ->orderBy('exam_task_exercise.position')
            ->orderBy('exercises.id')
            ->select('exercises.*')
            ->with(['options', 'answers'])
            ->get();
    }

    /**
     * Mark an objective task item by item. The mark is final and reproducible:
     * no model is consulted anywhere on this path.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function recordObjective(ExamAttempt $attempt, ExamSectionAttempt $sectionAttempt, ExamTask $task, array $payload): array
    {
        $answers = $payload['answers'] ?? [];
        $exercises = $this->taskExercises($task);
        $ability = $this->difficulty->abilityFor($attempt->user_id, $sectionAttempt->section->skill_id);

        $items = [];
        $raw = 0.0;
        $markable = 0;

        foreach ($exercises as $exercise) {
            $response = $answers[$exercise->id] ?? $answers[(string) $exercise->id] ?? null;
            $mark = $this->grader->grade($exercise, $response);
            $unmarkable = (bool) ($mark['detail']['unmarkable'] ?? false);

            if (! $unmarkable) {
                $raw += $mark['score'];
                $markable++;
            }

            ExerciseAttempt::create([
                'user_id' => $attempt->user_id,
                'exercise_id' => $exercise->id,
                'response' => is_array($response) ? $response : ['value' => $response],
                'is_correct' => $mark['is_correct'],
                'score' => $mark['score'],
                'ability_at_attempt' => $ability,
                'predicted_success' => $this->difficulty->successProbability(
                    $ability, (float) $exercise->difficulty,
                    (float) ($exercise->discrimination ?: 1.0), (float) $exercise->guessing,
                ),
                'feedback' => [
                    'source' => 'exam',
                    'exam_attempt_id' => $attempt->id,
                    'exam_section_attempt_id' => $sectionAttempt->id,
                    'exam_task_id' => $task->id,
                    'exam_task_type' => $task->taskType?->code,
                    'expected' => $mark['expected'],
                    'detail' => $mark['detail'],
                ],
                'answered_at' => now(),
            ]);

            if (! $mark['is_correct'] && ! $unmarkable && $response !== null) {
                $this->rememberMistake($attempt, $task, $exercise, $mark);
            }

            $items[] = [
                'exercise_id' => $exercise->id,
                'is_correct' => $mark['is_correct'],
                'score' => $mark['score'],
                'expected' => $mark['expected'],
                'given' => $mark['given'],
                'unmarkable' => $unmarkable,
            ];
        }

        return [
            'kind' => SectionScoring::MODE_OBJECTIVE,
            'raw_score' => round($raw, 4),
            'items_marked' => $markable,
            'items' => $items,
        ];
    }

    /**
     * Store a written or spoken response verbatim. Scoring happens at finish so
     * one model call can see the whole section, and so a scoring failure never
     * costs the learner their work.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function recordProductive(ExamAttempt $attempt, ExamSectionAttempt $sectionAttempt, ExamTask $task, array $payload): array
    {
        $ids = array_values(array_filter(array_map(
            'intval',
            (array) ($payload['speech_attempt_ids'] ?? array_filter([$payload['speech_attempt_id'] ?? null])),
        )));
        $text = isset($payload['text']) ? trim((string) $payload['text']) : null;

        $common = array_filter([
            'task_title' => $task->title,
            'task_instructions' => $task->instructions,
            'prompt_questions' => isset($payload['questions']) ? array_values((array) $payload['questions']) : null,
        ], fn ($v) => $v !== null && $v !== []);

        if ($ids) {
            $speech = SpeechAttempt::whereIn('id', $ids)
                ->where('user_id', $attempt->user_id)
                ->orderBy('id')
                ->get();

            if ($speech->count() !== count($ids)) {
                throw new ExamException('exam_speech_attempt_not_found', 'That recording does not belong to this learner.', 404);
            }

            $transcript = $text ?: $speech->pluck('transcript')->filter()->implode("\n\n");

            return $common + [
                'kind' => 'speaking',
                'speech_attempt_ids' => $speech->pluck('id')->all(),
                'transcript' => $transcript,
                'duration_ms' => (int) $speech->sum('duration_ms'),
                // Real acoustic measurements averaged across the part, kept as
                // evidence so the rubric prompt is anchored in something measured.
                'measured' => $this->measuredSignals($speech),
            ];
        }

        return $common + [
            'kind' => 'writing',
            'text' => $text,
            'word_count' => $text ? count(preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: []) : 0,
        ];
    }

    /**
     * @param  Collection<int, SpeechAttempt>  $speech
     * @return array<string, float>
     */
    private function measuredSignals(Collection $speech): array
    {
        $fields = ['pronunciation_score', 'fluency_score', 'speech_rate_wpm', 'pause_count', 'filler_count'];
        $out = [];

        foreach ($fields as $field) {
            $values = $speech->pluck($field)->filter(fn ($v) => $v !== null);
            if ($values->isNotEmpty()) {
                $out[$field] = round((float) $values->avg(), 2);
            }
        }

        return $out;
    }

    /** @param  array<string, mixed>  $record */
    private function writeResponseRow(ExamAttempt $attempt, ExamSectionAttempt $sectionAttempt, ExamTask $task, array $record): void
    {
        ExamScore::updateOrCreate(
            ['exam_attempt_id' => $attempt->id, 'criterion' => self::RESPONSE_CRITERION.$task->id],
            [
                'exam_section_attempt_id' => $sectionAttempt->id,
                'score' => (float) ($record['raw_score'] ?? 0),
                'rationale' => null,
                'evidence' => $record,
            ],
        );
    }

    /**
     * Push the mistake into the shared error log so exam practice feeds the main
     * curriculum instead of sitting in its own silo (spec 32).
     *
     * @param  array<string, mixed>  $mark
     */
    private function rememberMistake(ExamAttempt $attempt, ExamTask $task, Exercise $exercise, array $mark): void
    {
        $conceptId = DB::table('exercise_concepts')
            ->where('exercise_id', $exercise->id)
            ->orderByDesc('is_primary')
            ->orderByDesc('weight')
            ->value('concept_id');

        $skillCode = DB::table('skills')->where('id', $exercise->skill_id)->value('code');

        $this->remediation->recordError(
            userId: $attempt->user_id,
            errorType: $this->errorTypeFor($skillCode),
            conceptId: $conceptId ? (int) $conceptId : null,
            skillId: $exercise->skill_id,
            input: $mark['given'],
            expected: $mark['expected'],
            // The exam question type is the subtype, which is what makes
            // "always loses marks on matching headings" visible later.
            subtype: $task->taskType?->code,
            severity: 2,
            confidence: 1.0,
            note: 'Missed in exam practice: '.($task->taskType?->name ?? $task->title),
        );
    }

    private function errorTypeFor(?string $skillCode): string
    {
        return match ($skillCode) {
            'vocabulary' => 'vocabulary_confusion',
            'grammar' => 'grammar',
            'reading' => 'reading',
            'listening' => 'listening',
            'writing' => 'writing',
            'speaking' => 'speaking',
            'pronunciation' => 'pronunciation',
            default => 'exam_task',
        };
    }

    private function closeSection(ExamSectionAttempt $sectionAttempt, bool $ranOutOfTime): void
    {
        $started = $sectionAttempt->started_at ?? now();
        $allowed = (int) $sectionAttempt->section->duration_minutes * 60;
        $used = (int) min($allowed, max(0, now()->diffInSeconds($started, absolute: true)));

        $sectionAttempt->update([
            'status' => 'completed',
            'ran_out_of_time' => $ranOutOfTime || $sectionAttempt->ran_out_of_time,
            'duration_seconds' => $used,
            'completed_at' => now(),
        ]);
    }

    /**
     * Mean difficulty of a task's items, used only for practice-mode targeting.
     *
     * @param  int[]  $taskIds
     * @return array<int, float>
     */
    private function taskDifficulties(array $taskIds): array
    {
        if (! $taskIds) {
            return [];
        }

        return DB::table('exam_task_exercise')
            ->join('exercises', 'exercises.id', '=', 'exam_task_exercise.exercise_id')
            ->whereIn('exam_task_exercise.exam_task_id', $taskIds)
            ->whereNull('exercises.deleted_at')
            ->groupBy('exam_task_exercise.exam_task_id')
            ->pluck(DB::raw('avg(exercises.difficulty)'), 'exam_task_exercise.exam_task_id')
            ->map(fn ($v) => (float) $v)
            ->all();
    }
}
