<?php

namespace App\Services\Exam;

use App\AI\AiOrchestrator;
use App\AI\Support\TextRequest;
use App\Models\CefrLevel;
use App\Models\ExamAttempt;
use App\Models\ExamScore;
use App\Models\ExamScoreBand;
use App\Models\ExamSection;
use App\Models\ExamSectionAttempt;
use App\Models\ExamType;
use App\Models\LearnerProfile;
use App\Models\LearnerSkillState;
use App\Services\Learning\RemediationService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Turns recorded responses into per-criterion scores, a section score, an
 * overall estimate and a CEFR level.
 *
 * Two paths, deliberately kept apart. Objective sections are arithmetic on the
 * answer key and never touch a model. Productive sections go to the orchestrator
 * with the rubric expressed as a JSON schema, and if that call fails the section
 * is left unscored - an invented band is worse than a missing one.
 *
 * Sections the learner did not sit are filled from the ability the curriculum
 * already knows about, clearly flagged as projected, so a single-section
 * rehearsal still yields a whole-exam picture without pretending to have
 * measured it.
 */
class ScoringService
{
    public const FEATURE_RUBRIC = 'exam.rubric_scoring';

    /** Error vocabulary shared with learner_errors, so exam findings merge with curriculum ones. */
    public const ERROR_TYPES = [
        'vocabulary_confusion', 'grammar', 'pronunciation', 'listening', 'spelling',
        'word_order', 'article', 'preposition', 'collocation', 'register', 'cohesion', 'task_response',
    ];

    public function __construct(
        private AiOrchestrator $ai,
        private ExamService $exams,
        private RemediationService $remediation,
        private ExamAnalyticsService $analytics,
    ) {}

    // ------------------------------------------------------------- attempt

    public function scoreAttempt(ExamAttempt $attempt): ExamAttempt
    {
        $examType = $attempt->examType;
        $sections = $examType->sections()->with('skill')->orderBy('position')->get();

        $sectionResults = [];
        $usedAi = false;

        foreach ($sections as $section) {
            $sectionAttempt = $attempt->sectionAttempts->firstWhere('exam_section_id', $section->id);

            $result = $sectionAttempt
                ? $this->scoreSection($attempt, $sectionAttempt->load('section'))
                : ['score' => null, 'source' => null, 'criteria' => []];

            if ($result['score'] === null) {
                $prior = $this->curriculumPrior($attempt->user_id, $examType, $section);
                if ($prior !== null) {
                    $result = ['score' => $prior, 'source' => 'curriculum_prior', 'criteria' => $result['criteria'] ?? []];
                }
            }

            if (in_array($result['source'], ['ai', 'curriculum_prior'], true)) {
                $usedAi = true;
            }

            $sectionResults[$section->code] = $result + [
                'section' => $section,
                'skill' => $section->skill?->code,
            ];
        }

        $overall = $this->aggregate($examType, $sections, $sectionResults);
        $level = $overall !== null ? $this->cefrFor($examType, $overall) : null;

        $attempt->update([
            'estimated_score' => $overall,
            'estimated_cefr_level_id' => $level?->id,
            // Deterministic only when every section came off an answer key.
            'is_ai_estimated' => $usedAi,
            'time_management' => $this->analytics->timeManagement($attempt),
        ]);

        return $attempt->fresh(['sectionAttempts.section', 'scores', 'examType']);
    }

    /**
     * Score one section.
     *
     * @return array{score: ?float, source: ?string, criteria: array<string, float>, reason?: string}
     */
    public function scoreSection(ExamAttempt $attempt, ExamSectionAttempt $sectionAttempt): array
    {
        $section = $sectionAttempt->section;
        $scoring = SectionScoring::for($section);
        $responses = $this->exams->responsesFor($attempt, $sectionAttempt);

        if ($responses->isEmpty()) {
            return ['score' => null, 'source' => null, 'criteria' => [], 'reason' => 'not_attempted'];
        }

        return $scoring->isObjective()
            ? $this->scoreObjectiveSection($sectionAttempt, $scoring, $responses)
            : $this->scoreRubricSection($attempt, $sectionAttempt, $scoring, $responses);
    }

    // ----------------------------------------------------------- objective

    /**
     * @param  Collection<int, ExamScore>  $responses
     * @return array{score: ?float, source: string, criteria: array<string, float>}
     */
    private function scoreObjectiveSection(ExamSectionAttempt $sectionAttempt, SectionScoring $scoring, Collection $responses): array
    {
        $raw = 0.0;
        foreach ($responses as $response) {
            $raw += (float) ($response->evidence['raw_score'] ?? $response->score);
        }

        // Unanswered items are worth nothing, exactly as in the real test: the
        // raw total is measured against the full paper, not against what was seen.
        $score = $scoring->scoreFromRaw($raw);

        $sectionAttempt->update([
            'raw_score' => round($raw, 2),
            'estimated_score' => $score,
            'status' => 'scored',
        ]);

        return ['score' => $score, 'source' => 'answer_key', 'criteria' => []];
    }

    // -------------------------------------------------------------- rubric

    /**
     * @param  Collection<int, ExamScore>  $responses
     * @return array{score: ?float, source: ?string, criteria: array<string, float>, reason?: string}
     */
    private function scoreRubricSection(
        ExamAttempt $attempt,
        ExamSectionAttempt $sectionAttempt,
        SectionScoring $scoring,
        Collection $responses,
    ): array {
        $section = $sectionAttempt->section;
        $perTask = [];

        foreach ($responses as $response) {
            $evidence = $response->evidence ?? [];
            $judged = $evidence['ai_criteria'] ?? null;

            if ($judged === null) {
                $judged = $this->judge($attempt, $section, $scoring, $evidence);
                if ($judged === null) {
                    continue;
                }
                $evidence['ai_criteria'] = $judged['criteria'];
                $evidence['ai_summary'] = $judged['summary'];
                $evidence['scored_at'] = now()->toIso8601String();
                $response->update(['evidence' => $evidence]);

                $this->recordReportedErrors($attempt, $section, $judged['errors']);
                $judged = $judged['criteria'];
            }

            $perTask[] = [
                'task_type' => $evidence['exam_task_type'] ?? null,
                'weight' => $scoring->taskWeight($evidence['exam_task_type'] ?? null),
                'criteria' => collect($judged)->keyBy('code')->map(fn ($c) => (float) $c['score'])->all(),
                'rationales' => collect($judged)->keyBy('code')->map(fn ($c) => (string) ($c['rationale'] ?? ''))->all(),
                'evidence' => collect($judged)->keyBy('code')->map(fn ($c) => $c['evidence'] ?? [])->all(),
            ];
        }

        if (! $perTask) {
            $sectionAttempt->update(['status' => 'scoring_unavailable']);

            return ['score' => null, 'source' => null, 'criteria' => [], 'reason' => 'ai_scoring_unavailable'];
        }

        $criterionScores = $this->weightedCriterionMeans($scoring, $perTask);
        $this->writeCriterionRows($attempt, $sectionAttempt, $scoring, $criterionScores, $perTask);

        $score = $scoring->scoreFromCriteria($criterionScores);

        $sectionAttempt->update([
            'estimated_score' => $score,
            'status' => 'scored',
        ]);

        return ['score' => $score, 'source' => 'ai', 'criteria' => $criterionScores];
    }

    /**
     * One model call for one productive response, constrained to the rubric.
     *
     * @param  array<string, mixed>  $evidence  the stored submission
     * @return array{criteria: array<int, array<string, mixed>>, errors: array<int, array<string, mixed>>, summary: ?string}|null
     */
    public function judge(ExamAttempt $attempt, ExamSection $section, SectionScoring $scoring, array $evidence): ?array
    {
        $response = $this->responseText($evidence);
        if ($response === null || $response === '') {
            return null;
        }

        $scale = $scoring->criterionScale();

        $result = $this->ai->text(new TextRequest(
            feature: self::FEATURE_RUBRIC,
            prompt: $this->rubricPrompt($attempt->examType, $section, $scoring, $evidence, $response),
            system: $this->rubricSystemPrompt($attempt->examType, $section, $scoring),
            schema: $this->rubricSchema($scoring),
            // Marking should be repeatable, so the sampling temperature is low.
            temperature: 0.15,
            maxTokens: 1600,
            userId: $attempt->user_id,
            metadata: [
                'exam_type' => $attempt->examType->code,
                'exam_section' => $section->code,
                'exam_attempt_id' => $attempt->id,
                'exam_task_id' => $evidence['exam_task_id'] ?? null,
                'exam_task_type' => $evidence['exam_task_type'] ?? null,
                'criterion_scale' => $scale,
            ],
            // The prompt carries one learner's own work; never serve it from,
            // or add it to, a shared cache.
            cacheable: false,
        ));

        if (! $result->ok || ! is_array($result->json)) {
            Log::warning('exam.rubric_scoring.failed', [
                'exam_attempt_id' => $attempt->id,
                'exam_section' => $section->code,
                'error' => $result->error,
            ]);

            return null;
        }

        $criteria = $this->normaliseCriteria($result->json['criteria'] ?? [], $scoring);
        if (! $criteria) {
            Log::warning('exam.rubric_scoring.unusable_payload', [
                'exam_attempt_id' => $attempt->id,
                'exam_section' => $section->code,
            ]);

            return null;
        }

        return [
            'criteria' => $criteria,
            'errors' => is_array($result->json['errors'] ?? null) ? $result->json['errors'] : [],
            'summary' => isset($result->json['summary']) ? (string) $result->json['summary'] : null,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function normaliseCriteria(mixed $raw, SectionScoring $scoring): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $allowed = $scoring->criterionCodes();
        $scale = $scoring->criterionScale();
        $out = [];

        foreach ($raw as $row) {
            if (! is_array($row) || ! isset($row['code'], $row['score'])) {
                continue;
            }
            $code = (string) $row['code'];
            if (! in_array($code, $allowed, true) || isset($out[$code])) {
                continue;
            }
            $out[$code] = [
                'code' => $code,
                // Snap onto the published rubric steps; half bands exist, 6.37 does not.
                'score' => SectionScoring::roundToStep((float) $row['score'], $scale['step'], $scale['min'], $scale['max']),
                'rationale' => isset($row['rationale']) ? (string) $row['rationale'] : null,
                'evidence' => array_values(array_filter(
                    is_array($row['evidence'] ?? null) ? $row['evidence'] : [],
                    fn ($v) => is_string($v) && $v !== '',
                )),
            ];
        }

        // A partial rubric is not a rubric: every criterion must be judged.
        return count($out) === count($allowed) ? array_values($out) : [];
    }

    /**
     * @param  array<int, array<string, mixed>>  $perTask
     * @return array<string, float>
     */
    private function weightedCriterionMeans(SectionScoring $scoring, array $perTask): array
    {
        $scale = $scoring->criterionScale();
        $out = [];

        foreach ($scoring->criterionCodes() as $code) {
            $weighted = 0.0;
            $weight = 0.0;
            foreach ($perTask as $task) {
                if (! array_key_exists($code, $task['criteria'])) {
                    continue;
                }
                $weighted += $task['weight'] * $task['criteria'][$code];
                $weight += $task['weight'];
            }
            if ($weight > 0) {
                $out[$code] = SectionScoring::roundToStep($weighted / $weight, $scale['step'], $scale['min'], $scale['max']);
            }
        }

        return $out;
    }

    /**
     * @param  array<string, float>  $criterionScores
     * @param  array<int, array<string, mixed>>  $perTask
     */
    private function writeCriterionRows(
        ExamAttempt $attempt,
        ExamSectionAttempt $sectionAttempt,
        SectionScoring $scoring,
        array $criterionScores,
        array $perTask,
    ): void {
        foreach ($criterionScores as $code => $score) {
            $rationales = array_values(array_filter(array_map(fn ($t) => $t['rationales'][$code] ?? null, $perTask)));
            $quotes = array_merge(...array_map(fn ($t) => $t['evidence'][$code] ?? [], $perTask));

            ExamScore::updateOrCreate(
                [
                    'exam_attempt_id' => $attempt->id,
                    'exam_section_attempt_id' => $sectionAttempt->id,
                    'criterion' => $code,
                ],
                [
                    'score' => $score,
                    'rationale' => implode(' ', $rationales) ?: null,
                    'evidence' => [
                        'is_ai_estimated' => true,
                        'disclaimer' => ExamEstimate::AI_DISCLAIMER,
                        'scale' => $scoring->criterionScale(),
                        'quotes' => array_values(array_slice($quotes, 0, 8)),
                        'per_task' => array_map(fn ($t) => [
                            'task_type' => $t['task_type'],
                            'weight' => $t['weight'],
                            'score' => $t['criteria'][$code] ?? null,
                        ], $perTask),
                    ],
                ],
            );
        }
    }

    // ---------------------------------------------------------- aggregation

    /**
     * @param  Collection<int, ExamSection>  $sections
     * @param  array<string, array<string, mixed>>  $results
     */
    private function aggregate(ExamType $examType, Collection $sections, array $results): ?float
    {
        $scores = [];
        foreach ($sections as $section) {
            $score = $results[$section->code]['score'] ?? null;
            if ($score === null) {
                // A partial paper cannot be reported as an overall score.
                return null;
            }
            $scores[] = (float) $score;
        }

        if (! $scores) {
            return null;
        }

        $aggregation = SectionScoring::for($sections->first())->aggregation();
        $value = $aggregation === 'sum' ? array_sum($scores) : array_sum($scores) / count($scores);

        return SectionScoring::roundToStep(
            $value,
            (float) $examType->score_step,
            (float) $examType->score_min,
            (float) $examType->score_max,
        );
    }

    /**
     * A section the learner did not sit is projected from the ability the rest of
     * the app already measured, using the same band table in reverse.
     */
    public function curriculumPrior(int $userId, ExamType $examType, ExamSection $section): ?float
    {
        $levelId = LearnerSkillState::where('user_id', $userId)
            ->where('skill_id', $section->skill_id)
            ->value('cefr_level_id');

        if (! $levelId) {
            $ability = LearnerSkillState::where('user_id', $userId)
                ->where('skill_id', $section->skill_id)
                ->value('ability');
            $ability ??= LearnerProfile::where('user_id', $userId)->value('ability');
            if ($ability === null) {
                return null;
            }
            $levelId = CefrLevel::where('ability_min', '<=', $ability)
                ->where('ability_max', '>', $ability)
                ->orderBy('ordinal')->value('id');
        }

        if (! $levelId) {
            return null;
        }

        $band = ExamScoreBand::where('exam_type_id', $examType->id)
            ->where('cefr_level_id', $levelId)
            ->first();

        if (! $band) {
            return null;
        }

        $scoring = SectionScoring::for($section);
        $scale = $scoring->sectionScale();

        // Mid-band, snapped to the section's own reporting step.
        return SectionScoring::roundToStep(
            ((float) $band->score_from + (float) $band->score_to) / 2,
            $scale['step'],
            $scale['min'],
            $scale['max'],
        );
    }

    // ------------------------------------------------------ band -> CEFR

    public function bandFor(ExamType $examType, float $score): ?ExamScoreBand
    {
        return ExamScoreBand::where('exam_type_id', $examType->id)
            ->where('score_from', '<=', $score)
            ->where('score_to', '>=', $score)
            ->orderBy('score_from')
            ->first();
    }

    public function cefrFor(ExamType $examType, float $score): ?CefrLevel
    {
        $band = $this->bandFor($examType, $score);
        if ($band) {
            return $band->cefrLevel;
        }

        // Outside every published band (a score below or above the mapped range):
        // clamp to the nearest end rather than reporting no level at all.
        $bands = ExamScoreBand::where('exam_type_id', $examType->id)->orderBy('score_from')->get();
        if ($bands->isEmpty()) {
            return null;
        }

        return $score < (float) $bands->first()->score_from
            ? $bands->first()->cefrLevel
            : $bands->last()->cefrLevel;
    }

    /** @return array<int, array<string, mixed>> the whole mapping, for the exam profile endpoint */
    public function bandTable(ExamType $examType): array
    {
        return ExamScoreBand::with('cefrLevel')
            ->where('exam_type_id', $examType->id)
            ->orderBy('score_from')
            ->get()
            ->map(fn (ExamScoreBand $b) => [
                'score_from' => (float) $b->score_from,
                'score_to' => (float) $b->score_to,
                'cefr' => $b->cefrLevel?->code,
                'cefr_name' => $b->cefrLevel?->name,
            ])->all();
    }

    // ------------------------------------------------------------ prompts

    private function rubricSystemPrompt(ExamType $examType, ExamSection $section, SectionScoring $scoring): string
    {
        $scale = $scoring->criterionScale();
        $lines = [
            "You are an experienced {$examType->name} examiner marking the {$section->name} paper.",
            'Apply the published band descriptors strictly and consistently. Mark only what is in front of you: '
                .'do not reward intent, do not penalise opinions, and do not assume anything about the candidate.',
            sprintf('Judge every criterion on a %s to %s scale in steps of %s.',
                $this->num($scale['min']), $this->num($scale['max']), $this->num($scale['step'])),
            'The criteria are:',
        ];

        foreach ($scoring->criteria() as $criterion) {
            $lines[] = sprintf('- %s (%s): %s', $criterion['code'], $criterion['name'], $criterion['descriptor'] ?? '');
        }

        $lines[] = 'For each criterion give a short rationale and quote the candidate\'s own words as evidence.';
        $lines[] = 'Also list the specific language errors you noticed, using the given error types, so they can be '
            .'practised later. Report only errors you can point to in the response.';
        $lines[] = 'Return JSON matching the supplied schema and nothing else.';

        return implode("\n", $lines);
    }

    /** @param  array<string, mixed>  $evidence */
    private function rubricPrompt(ExamType $examType, ExamSection $section, SectionScoring $scoring, array $evidence, string $response): string
    {
        $taskType = $evidence['exam_task_type'] ?? 'task';
        $parts = [
            "Exam: {$examType->name}",
            "Section: {$section->name}",
            "Task type: {$taskType}",
        ];

        if (! empty($evidence['task_title'])) {
            $parts[] = 'Task title: '.$evidence['task_title'];
        }
        if (! empty($evidence['task_instructions'])) {
            $parts[] = "Task instructions:\n".$evidence['task_instructions'];
        }
        if (($evidence['kind'] ?? null) === 'writing') {
            $parts[] = 'Word count: '.($evidence['word_count'] ?? 0);
        }
        if (! empty($evidence['measured'])) {
            // Acoustic facts from forced alignment, not opinions: they anchor the
            // pronunciation and fluency judgements in something measured.
            $parts[] = 'Measured speech signals (0-100 unless stated): '.json_encode($evidence['measured'], JSON_UNESCAPED_UNICODE);
        }
        if (! empty($evidence['prompt_questions'])) {
            $parts[] = "Examiner questions asked:\n- ".implode("\n- ", $evidence['prompt_questions']);
        }

        $label = ($evidence['kind'] ?? null) === 'speaking' ? 'Candidate transcript' : 'Candidate response';
        $parts[] = "{$label}:\n\"\"\"\n{$response}\n\"\"\"";
        $parts[] = 'Mark this response against every criterion listed in the system instructions.';

        unset($scoring);

        return implode("\n\n", $parts);
    }

    /** @return array<string, mixed> */
    private function rubricSchema(SectionScoring $scoring): array
    {
        $codes = $scoring->criterionCodes();
        $scale = $scoring->criterionScale();

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['criteria'],
            'properties' => [
                'criteria' => [
                    'type' => 'array',
                    'minItems' => count($codes),
                    'maxItems' => count($codes),
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['code', 'score', 'rationale'],
                        'properties' => [
                            'code' => ['type' => 'string', 'enum' => $codes],
                            'score' => ['type' => 'number', 'minimum' => $scale['min'], 'maximum' => $scale['max']],
                            'rationale' => ['type' => 'string', 'maxLength' => 600],
                            'evidence' => [
                                'type' => 'array',
                                'maxItems' => 4,
                                'items' => ['type' => 'string', 'maxLength' => 240],
                            ],
                        ],
                    ],
                ],
                'errors' => [
                    'type' => 'array',
                    'maxItems' => 12,
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['error_type', 'input'],
                        'properties' => [
                            'error_type' => ['type' => 'string', 'enum' => self::ERROR_TYPES],
                            'subtype' => ['type' => 'string', 'maxLength' => 64],
                            'input' => ['type' => 'string', 'maxLength' => 240],
                            'expected' => ['type' => 'string', 'maxLength' => 240],
                            'severity' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 5],
                        ],
                    ],
                ],
                'summary' => ['type' => 'string', 'maxLength' => 800],
            ],
        ];
    }

    /** @param  array<string, mixed>  $evidence */
    private function responseText(array $evidence): ?string
    {
        $text = $evidence['text'] ?? $evidence['transcript'] ?? null;

        return is_string($text) && trim($text) !== '' ? trim($text) : null;
    }

    /** @param  array<int, array<string, mixed>>  $errors */
    private function recordReportedErrors(ExamAttempt $attempt, ExamSection $section, array $errors): void
    {
        foreach ($errors as $error) {
            if (! is_array($error) || empty($error['error_type']) || empty($error['input'])) {
                continue;
            }
            if (! in_array($error['error_type'], self::ERROR_TYPES, true)) {
                continue;
            }

            $this->remediation->recordError(
                userId: $attempt->user_id,
                errorType: (string) $error['error_type'],
                conceptId: null,
                skillId: $section->skill_id,
                input: mb_substr((string) $error['input'], 0, 240),
                expected: isset($error['expected']) ? mb_substr((string) $error['expected'], 0, 240) : null,
                subtype: isset($error['subtype']) ? mb_substr((string) $error['subtype'], 0, 64) : $section->code,
                severity: (int) ($error['severity'] ?? 2),
                // An AI-spotted error is a strong hint, not a certainty; the lower
                // confidence keeps it from outranking errors we marked ourselves.
                confidence: 0.75,
                note: "Noted by the AI examiner during {$section->name} practice.",
            );
        }
    }

    private function num(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.') ?: '0';
    }

    /** @return array<string, mixed> per-skill estimates for the result screen */
    public function skillBreakdown(ExamAttempt $attempt): array
    {
        $rows = DB::table('exam_section_attempts')
            ->join('exam_sections', 'exam_sections.id', '=', 'exam_section_attempts.exam_section_id')
            ->join('skills', 'skills.id', '=', 'exam_sections.skill_id')
            ->where('exam_section_attempts.exam_attempt_id', $attempt->id)
            ->select('skills.code as skill', 'exam_sections.code as section', 'exam_sections.name as section_name',
                'exam_section_attempts.estimated_score', 'exam_section_attempts.raw_score',
                'exam_section_attempts.status', 'exam_section_attempts.ran_out_of_time')
            ->get();

        return $rows->map(fn ($r) => [
            'skill' => $r->skill,
            'section' => $r->section,
            'section_name' => $r->section_name,
            'estimated_score' => $r->estimated_score !== null ? (float) $r->estimated_score : null,
            'raw_score' => $r->raw_score !== null ? (float) $r->raw_score : null,
            'status' => $r->status,
            'ran_out_of_time' => (bool) $r->ran_out_of_time,
        ])->all();
    }
}
