<?php

namespace App\Services\Exam;

use App\AI\AiOrchestrator;
use App\AI\Support\TextRequest;
use App\Models\ConversationSession;
use App\Models\ConversationTurn;
use App\Models\ExamAttempt;
use App\Models\ExamSectionAttempt;
use App\Models\ExamTask;
use App\Models\ProductionPrompt;
use App\Models\SpeechAttempt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The AI examiner for the speaking test.
 *
 * It runs the interview the exam profile describes - for IELTS that is Part 1
 * questions, the Part 2 long turn with its preparation minute, and the Part 3
 * discussion - asking questions, taking each recorded answer, and finally
 * marking the whole performance against the four speaking criteria.
 *
 * Every score it produces is an estimate and is stored and served as one.
 *
 * The interview lives in conversation_sessions / conversation_turns, which
 * already model a spoken exchange with per-turn recordings and silently
 * observed errors. The exam link is carried in objectives_met because the
 * schema has no exam foreign key on that table; INTEGRATION.md notes the
 * migration that would make it explicit.
 */
class AiExaminerService
{
    public const FEATURE_QUESTIONS = 'exam.speaking_questions';

    public function __construct(
        private AiOrchestrator $ai,
        private ExamService $exams,
        private ScoringService $scoring,
    ) {}

    /**
     * Current interview state and the next thing the examiner says.
     *
     * @return array<string, mixed>
     */
    public function interview(ExamAttempt $attempt): array
    {
        $sectionAttempt = $this->speakingSection($attempt);
        $section = $sectionAttempt->section;
        $scoring = SectionScoring::for($section);
        $session = $this->session($attempt, $sectionAttempt);

        $state = $session->objectives_met ?? [];

        foreach ($scoring->parts() as $part) {
            $code = (string) $part['code'];
            $partState = $state['parts'][$code] ?? null;

            if (! $partState) {
                $partState = $this->openPart($attempt, $sectionAttempt, $part);
                $state['parts'][$code] = $partState;
                $state['current_part'] = $code;
                $session->update(['objectives_met' => $state]);
            }

            if (! empty($partState['complete'])) {
                continue;
            }

            $index = (int) $partState['answered'];
            if ($index >= count($partState['questions'])) {
                $state = $this->closePart($attempt, $sectionAttempt, $session, $state, $code);

                continue;
            }

            $this->askIfNeeded($session, $partState['questions'][$index], $code, $index);

            return [
                'complete' => false,
                'section_attempt' => $sectionAttempt,
                'conversation_session_id' => $session->id,
                'part' => [
                    'code' => $code,
                    'name' => $part['name'] ?? $code,
                    'question_number' => $index + 1,
                    'question_total' => count($partState['questions']),
                ],
                'question' => $partState['questions'][$index],
                'cue_card' => $partState['cue_card'] ?? null,
                'prep_seconds' => (int) ($part['prep_seconds'] ?? 0),
                'response_seconds' => (int) ($part['response_seconds'] ?? 60),
                'section_remaining_seconds' => max(0, $this->exams->remainingSeconds($sectionAttempt)),
                'estimate_notice' => ExamEstimate::AI_DISCLAIMER,
            ];
        }

        $session->update(['objectives_met' => $state, 'status' => 'completed', 'completed_at' => now()]);

        return [
            'complete' => true,
            'section_attempt' => $sectionAttempt,
            'conversation_session_id' => $session->id,
            'estimate_notice' => ExamEstimate::AI_DISCLAIMER,
        ];
    }

    /**
     * Take one spoken answer and move the interview on.
     *
     * @return array<string, mixed>
     */
    public function respond(ExamAttempt $attempt, int $speechAttemptId): array
    {
        $sectionAttempt = $this->speakingSection($attempt);
        $session = $this->session($attempt, $sectionAttempt);
        $state = $session->objectives_met ?? [];
        $code = $state['current_part'] ?? null;

        if (! $code || empty($state['parts'][$code]) || ! empty($state['parts'][$code]['complete'])) {
            throw new ExamException('exam_no_open_question', 'There is no examiner question waiting for an answer.', 409);
        }

        $speech = SpeechAttempt::where('id', $speechAttemptId)->where('user_id', $attempt->user_id)->first();
        if (! $speech) {
            throw new ExamException('exam_speech_attempt_not_found', 'That recording does not belong to this learner.', 404);
        }

        $partState = $state['parts'][$code];
        $index = (int) $partState['answered'];

        DB::transaction(function () use ($session, $speech, $code, $index, &$state, $partState) {
            ConversationTurn::create([
                'conversation_session_id' => $session->id,
                'position' => (int) $session->turn_count,
                'speaker' => 'learner',
                'text' => (string) ($speech->transcript ?? ''),
                'speech_attempt_id' => $speech->id,
                'observed_errors' => ['exam_part' => $code, 'question_index' => $index],
            ]);
            $session->increment('turn_count');

            $partState['answered'] = $index + 1;
            $partState['speech_attempt_ids'][] = $speech->id;
            $state['parts'][$code] = $partState;
            $session->update(['objectives_met' => $state]);
        });

        return $this->interview($attempt->fresh(['sectionAttempts.section', 'examType']));
    }

    /**
     * Mark the speaking section. Returns the four criterion estimates, or a
     * reason when the model could not be reached - never an invented band.
     *
     * @return array<string, mixed>
     */
    public function score(ExamAttempt $attempt): array
    {
        $sectionAttempt = $this->speakingSection($attempt, requireOpen: false);
        $result = $this->scoring->scoreSection($attempt, $sectionAttempt);

        return [
            'section' => $sectionAttempt->section->code,
            'estimated_score' => $result['score'],
            'criteria' => $result['criteria'],
            'reason' => $result['reason'] ?? null,
        ] + ExamEstimate::label(aiEstimated: true);
    }

    // ------------------------------------------------------------ internals

    public function speakingSection(ExamAttempt $attempt, bool $requireOpen = true): ExamSectionAttempt
    {
        $attempt->loadMissing('sectionAttempts.section.skill');

        $candidates = $attempt->sectionAttempts
            ->filter(fn (ExamSectionAttempt $sa) => $sa->section->skill?->code === 'speaking')
            ->sortBy(fn (ExamSectionAttempt $sa) => $sa->section->position);

        if ($candidates->isEmpty()) {
            throw ExamException::notSpeaking();
        }

        $open = $candidates->firstWhere('status', 'in_progress') ?? $candidates->firstWhere('status', 'pending');

        if (! $requireOpen) {
            return ($open ?? $candidates->first())->load('section');
        }

        if (! $open) {
            throw ExamException::notSpeaking();
        }

        if ($open->status === 'pending') {
            $open->update(['status' => 'in_progress', 'started_at' => now()]);
        }

        return $open->fresh('section');
    }

    private function session(ExamAttempt $attempt, ExamSectionAttempt $sectionAttempt): ConversationSession
    {
        $existing = ConversationSession::where('user_id', $attempt->user_id)
            ->where('objectives_met->exam->exam_attempt_id', $attempt->id)
            ->latest('id')->first();

        if ($existing) {
            return $existing;
        }

        return ConversationSession::create([
            'user_id' => $attempt->user_id,
            'mode' => 'voice',
            'status' => 'active',
            'objectives_met' => [
                'exam' => [
                    'exam_attempt_id' => $attempt->id,
                    'exam_section_attempt_id' => $sectionAttempt->id,
                    'exam_section_id' => $sectionAttempt->exam_section_id,
                ],
                'parts' => [],
                'current_part' => null,
            ],
        ]);
    }

    /**
     * Prepare one part: find its authored task and get the questions the
     * examiner will ask.
     *
     * @param  array<string, mixed>  $part
     * @return array<string, mixed>
     */
    private function openPart(ExamAttempt $attempt, ExamSectionAttempt $sectionAttempt, array $part): array
    {
        $code = (string) $part['code'];
        $task = $this->taskForPart($attempt, $sectionAttempt, $code);

        if (! $task) {
            throw ExamException::noContent($sectionAttempt->section->name.' '.$code);
        }

        $prompt = $task->production_prompt_id ? ProductionPrompt::find($task->production_prompt_id) : null;
        $wanted = (int) ($part['max_questions'] ?? 1);
        $questions = $this->questionsFor($attempt, $sectionAttempt, $task, $prompt, $part, $wanted);

        return [
            'exam_task_id' => $task->id,
            'exam_task_type' => $code,
            'questions' => $questions,
            // The Part 2 cue card is authored content, shown verbatim during preparation.
            'cue_card' => $prompt?->prompt,
            'answered' => 0,
            'speech_attempt_ids' => [],
            'complete' => false,
        ];
    }

    /**
     * Close a finished part by handing its recordings to the exam as one
     * submission, which is what the scorer later marks.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function closePart(ExamAttempt $attempt, ExamSectionAttempt $sectionAttempt, ConversationSession $session, array $state, string $code): array
    {
        $partState = $state['parts'][$code];
        $task = ExamTask::with('taskType')->find($partState['exam_task_id']);

        if ($task && $partState['speech_attempt_ids']) {
            $this->exams->submitResponse($attempt, $task, [
                'speech_attempt_ids' => $partState['speech_attempt_ids'],
                'questions' => $partState['questions'],
            ]);
        }

        $state['parts'][$code]['complete'] = true;
        $session->update(['objectives_met' => $state]);

        unset($sectionAttempt);

        return $state;
    }

    private function taskForPart(ExamAttempt $attempt, ExamSectionAttempt $sectionAttempt, string $taskTypeCode): ?ExamTask
    {
        return $this->exams->sectionTasks($attempt, $sectionAttempt->section)
            ->first(fn (ExamTask $t) => $t->taskType?->code === $taskTypeCode);
    }

    /**
     * Questions for one part. The model personalises them around the authored
     * topic; if it is unavailable the authored questions are used as written,
     * because an examiner with nothing to ask is better than an invented paper.
     *
     * @param  array<string, mixed>  $part
     * @return string[]
     */
    private function questionsFor(
        ExamAttempt $attempt,
        ExamSectionAttempt $sectionAttempt,
        ExamTask $task,
        ?ProductionPrompt $prompt,
        array $part,
        int $wanted,
    ): array {
        $authored = $this->authoredQuestions($task, $prompt);

        if ($wanted <= 1) {
            return array_slice($authored ?: [$task->title], 0, 1);
        }

        $min = (int) ($part['min_questions'] ?? $wanted);

        $result = $this->ai->text(new TextRequest(
            feature: self::FEATURE_QUESTIONS,
            prompt: implode("\n\n", array_filter([
                'Exam: '.$attempt->examType->name,
                'Section: '.$sectionAttempt->section->name,
                'Part: '.($part['name'] ?? $part['code']),
                'Topic: '.$task->title,
                $task->instructions ? "Examiner notes:\n".$task->instructions : null,
                $authored ? "Authored questions to draw on:\n- ".implode("\n- ", $authored) : null,
                "Produce between {$min} and {$wanted} questions for this part, in the order they should be asked. "
                    .'Each must be answerable in one spoken turn and must sound like a real examiner, not a textbook.',
            ])),
            system: 'You write speaking-test questions for '.$attempt->examType->name.'. Stay inside the part\'s '
                .'published format, keep every question on the given topic, and never ask anything a candidate could '
                .'not answer without specialist knowledge. Return JSON only.',
            schema: [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['questions'],
                'properties' => [
                    'questions' => [
                        'type' => 'array',
                        'minItems' => $min,
                        'maxItems' => $wanted,
                        'items' => ['type' => 'string', 'maxLength' => 240],
                    ],
                ],
            ],
            temperature: 0.8,
            maxTokens: 600,
            userId: $attempt->user_id,
            metadata: [
                'exam_type' => $attempt->examType->code,
                'exam_section' => $sectionAttempt->section->code,
                'exam_task_id' => $task->id,
                'part' => $part['code'],
            ],
            cacheable: false,
        ));

        $questions = [];
        if ($result->ok && is_array($result->json)) {
            $questions = array_values(array_filter(
                array_map(fn ($q) => is_string($q) ? trim($q) : null, $result->json['questions'] ?? []),
                fn ($q) => $q !== null && $q !== '',
            ));
        } else {
            Log::info('exam.speaking_questions.fallback_to_authored', [
                'exam_attempt_id' => $attempt->id,
                'part' => $part['code'],
                'error' => $result->error,
            ]);
        }

        if (! $questions) {
            $questions = $authored;
        }

        if (! $questions) {
            throw ExamException::noContent($sectionAttempt->section->name.' '.$part['code']);
        }

        return array_slice($questions, 0, $wanted);
    }

    /** @return string[] */
    private function authoredQuestions(ExamTask $task, ?ProductionPrompt $prompt): array
    {
        $source = $task->instructions ?: $prompt?->prompt;
        if (! $source) {
            return [];
        }

        return array_values(array_filter(
            array_map(fn ($line) => trim(ltrim(trim($line), "-*0123456789. ")), preg_split('/\R/u', $source) ?: []),
            fn ($line) => $line !== '',
        ));
    }

    private function askIfNeeded(ConversationSession $session, string $question, string $part, int $index): void
    {
        $already = ConversationTurn::where('conversation_session_id', $session->id)
            ->where('speaker', 'ai')
            ->where('text', $question)
            ->exists();

        if ($already) {
            return;
        }

        ConversationTurn::create([
            'conversation_session_id' => $session->id,
            'position' => (int) $session->turn_count,
            'speaker' => 'ai',
            'text' => $question,
            'observed_errors' => ['exam_part' => $part, 'question_index' => $index],
        ]);
        $session->increment('turn_count');
    }
}
