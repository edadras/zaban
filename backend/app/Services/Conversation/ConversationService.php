<?php

namespace App\Services\Conversation;

use App\AI\AiOrchestrator;
use App\AI\Support\TextRequest;
use App\Models\ConversationScenario;
use App\Models\ConversationSession;
use App\Models\ConversationTurn;
use App\Models\LearnerProfile;
use App\Models\SpeechAttempt;
use App\Services\Learning\RemediationService;
use Illuminate\Support\Facades\DB;

/**
 * AI roleplay conversation (spec 26).
 *
 * The governing rule is that the conversation stays a conversation. Mistakes are
 * observed silently and only surfaced in the debrief at the end - correcting
 * every sentence turns practice into a test and stops people speaking. The one
 * exception is a mistake that actually blocks understanding, where the character
 * asks for clarification the way a real person would.
 */
class ConversationService
{
    public function __construct(
        private AiOrchestrator $ai,
        private RemediationService $remediation,
    ) {}

    public function start(int $userId, ConversationScenario $scenario, string $mode = 'voice'): ConversationSession
    {
        $session = ConversationSession::create([
            'user_id' => $userId,
            'conversation_scenario_id' => $scenario->id,
            'mode' => $mode,
            'status' => 'active',
        ]);

        $opening = $this->openingLine($scenario, $userId);
        $this->addTurn($session, 'ai', $opening);

        return $session->fresh('turns');
    }

    /**
     * Take the learner's turn and produce the character's reply.
     *
     * @param  string  $text  what the learner said (from STT, or typed)
     */
    public function respond(ConversationSession $session, string $text, ?SpeechAttempt $speech = null): ConversationTurn
    {
        $scenario = $session->scenario;

        $observations = $this->observe($session, $text, $scenario);

        DB::transaction(function () use ($session, $text, $speech, $observations) {
            $this->addTurn($session, 'learner', $text, $speech, $observations);
            $session->increment('turn_count');
        });

        $reply = $this->characterReply($session, $scenario, $observations);

        return $this->addTurn($session, 'ai', $reply);
    }

    /**
     * Close the conversation and produce the debrief - the only point at which
     * corrections are shown.
     */
    public function finish(ConversationSession $session): ConversationSession
    {
        $session->loadMissing('turns', 'scenario');

        $learnerTurns = $session->turns->where('speaker', 'learner');
        $errors = $learnerTurns->flatMap(fn (ConversationTurn $t) => $t->observed_errors ?? []);

        // The corrections that matter most: the ones that blocked understanding,
        // then the ones that recurred.
        $ranked = $errors
            ->groupBy(fn ($e) => ($e['type'] ?? 'unknown').'|'.($e['expected'] ?? ''))
            ->map(fn ($group) => [
                'type' => $group->first()['type'] ?? 'unknown',
                'said' => $group->first()['said'] ?? null,
                'expected' => $group->first()['expected'] ?? null,
                'note' => $group->first()['note'] ?? null,
                'occurrences' => $group->count(),
                'blocked' => (bool) $group->contains(fn ($e) => ($e['blocked'] ?? false)),
            ])
            ->sortByDesc(fn ($e) => ($e['blocked'] ? 100 : 0) + $e['occurrences'])
            ->take(3)
            ->values();

        // Everything observed is remembered, not just the three shown.
        foreach ($errors as $error) {
            $this->remediation->recordError(
                userId: $session->user_id,
                errorType: $error['type'] ?? 'grammar',
                skillId: null,
                input: $error['said'] ?? null,
                expected: $error['expected'] ?? null,
                note: $error['note'] ?? null,
                severity: ($error['blocked'] ?? false) ? 4 : 2,
            );
        }

        $objectives = $this->objectivesMet($session);

        $session->update([
            'status' => 'completed',
            'completed_at' => now(),
            'objectives_met' => $objectives,
            'summary' => [
                'went_well' => $this->strengths($session, $errors->count()),
                'corrections' => $ranked,
                'pronunciation' => $this->pronunciationNotes($session),
                'useful_vocabulary' => $this->vocabularySeen($session),
                'recommended_practice' => $this->recommendation($ranked),
                'turns' => $session->turn_count,
            ],
            'overall_score' => $this->score($session, $errors->count(), $objectives),
        ]);

        return $session->fresh();
    }

    // ------------------------------------------------------------- internals

    private function openingLine(ConversationScenario $scenario, int $userId): string
    {
        $level = LearnerProfile::with('cefrLevel')->where('user_id', $userId)->first()?->cefrLevel?->code ?? 'B1';

        $result = $this->ai->text(new TextRequest(
            feature: 'conversation.open',
            system: $this->systemPrompt($scenario, $level),
            prompt: 'Open the conversation with one short, natural line in character. '
                .'Do not greet the learner as a student or mention that this is practice.',
            temperature: 0.8,
            maxTokens: 200,
            userId: $userId,
            // Openings are scenario-specific but not learner-specific, so they
            // are worth caching.
            cacheable: true,
        ));

        return $result->ok && $result->text
            ? trim($result->text)
            // Falling back to the authored line keeps the scenario usable when
            // the model is unreachable, rather than failing the session.
            : $scenario->situation;
    }

    private function characterReply(ConversationSession $session, ConversationScenario $scenario, array $observations): string
    {
        $level = LearnerProfile::with('cefrLevel')->where('user_id', $session->user_id)->first()?->cefrLevel?->code ?? 'B1';
        $blocked = collect($observations)->contains(fn ($o) => $o['blocked'] ?? false);

        $history = $session->turns()->orderBy('position')->get()
            ->map(fn (ConversationTurn $t) => ($t->speaker === 'ai' ? 'You: ' : 'Them: ').$t->text)
            ->implode("\n");

        $instruction = $blocked
            ? 'The last thing they said was unclear. Ask for clarification the way a real person would - '
                .'naturally, in character, without correcting their grammar.'
            : 'Reply in character with one short natural turn. Do not correct their English. '
                .'Do not comment on their mistakes.';

        $result = $this->ai->text(new TextRequest(
            feature: 'conversation.reply',
            system: $this->systemPrompt($scenario, $level),
            prompt: "Conversation so far:\n{$history}\n\n{$instruction}",
            temperature: 0.85,
            maxTokens: 300,
            userId: $session->user_id,
            // Every conversation is different, so replies are never reused.
            cacheable: false,
        ));

        return $result->ok && $result->text
            ? trim($result->text)
            : ($blocked ? "Sorry, I didn't catch that - could you say it again?" : 'I see. Go on.');
    }

    private function systemPrompt(ConversationScenario $scenario, string $level): string
    {
        return implode(' ', [
            "You are playing a character in a spoken English practice scenario: {$scenario->ai_role}.",
            "Setting: {$scenario->setting}. Situation: {$scenario->situation}.",
            "The other person is an English learner at CEFR level {$level}; pitch your vocabulary and sentence length to that level.",
            'Stay in character at all times. Never mention that this is practice, never act as a teacher,',
            'and never correct their English - mistakes are handled elsewhere.',
            'Keep every turn to one or two sentences so they get to do most of the talking.',
        ]);
    }

    /**
     * Observe mistakes without reacting to them. Returns structured records that
     * are stored on the turn and surfaced only in the debrief.
     */
    private function observe(ConversationSession $session, string $text, ConversationScenario $scenario): array
    {
        $result = $this->ai->text(new TextRequest(
            feature: 'conversation.observe',
            system: 'You analyse a language learner\'s spoken turn. Report only real errors that a '
                .'teacher would note. Ignore hesitation, false starts and self-corrections - those are '
                .'normal speech. Mark blocked=true only when the error genuinely prevents understanding.',
            prompt: "Learner said: \"{$text}\"",
            schema: [
                'type' => 'object',
                'properties' => [
                    'errors' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'type' => ['type' => 'string', 'enum' => [
                                    'grammar', 'vocabulary_confusion', 'word_order', 'article',
                                    'preposition', 'spelling', 'register',
                                ]],
                                'said' => ['type' => 'string'],
                                'expected' => ['type' => 'string'],
                                'note' => ['type' => 'string'],
                                'blocked' => ['type' => 'boolean'],
                            ],
                            'required' => ['type', 'said', 'expected', 'blocked'],
                        ],
                    ],
                ],
                'required' => ['errors'],
            ],
            temperature: 0.2,
            userId: $session->user_id,
            cacheable: false,
        ));

        // No analyser available means no observations - never invented ones.
        return $result->ok && is_array($result->json['errors'] ?? null)
            ? $result->json['errors']
            : [];
    }

    private function addTurn(
        ConversationSession $session,
        string $speaker,
        string $text,
        ?SpeechAttempt $speech = null,
        array $observations = [],
    ): ConversationTurn {
        $position = (int) ConversationTurn::where('conversation_session_id', $session->id)->max('position') + 1;

        return ConversationTurn::create([
            'conversation_session_id' => $session->id,
            'position' => $position,
            'speaker' => $speaker,
            'text' => $text,
            'speech_attempt_id' => $speech?->id,
            'observed_errors' => $observations ?: null,
            'blocked_communication' => collect($observations)->contains(fn ($o) => $o['blocked'] ?? false),
        ]);
    }

    private function objectivesMet(ConversationSession $session): array
    {
        $objectives = $session->scenario?->objectives ?? [];
        if (! $objectives) {
            return [];
        }
        $said = $session->turns->where('speaker', 'learner')->pluck('text')->implode(' ');

        // A cheap lexical check: the debrief says which communicative goals were
        // attempted, and the learner can see what they did not get to.
        return collect($objectives)->map(fn ($o) => [
            'objective' => $o,
            'attempted' => str_contains(mb_strtolower($said), mb_strtolower((string) preg_split('/\s+/', (string) $o)[0])),
        ])->all();
    }

    private function strengths(ConversationSession $session, int $errorCount): array
    {
        $turns = $session->turns->where('speaker', 'learner');
        $words = $turns->sum(fn (ConversationTurn $t) => str_word_count($t->text));
        $out = [];

        if ($turns->count() >= 5) {
            $out[] = 'You kept the conversation going for '.$turns->count().' turns.';
        }
        if ($words > 0 && $turns->count() > 0 && ($words / $turns->count()) >= 8) {
            $out[] = 'Your answers were full sentences rather than single words.';
        }
        if ($errorCount === 0) {
            $out[] = 'Nothing you said got in the way of being understood.';
        } elseif ($session->turns->where('blocked_communication', true)->isEmpty()) {
            $out[] = 'Every turn was understandable, even where the grammar slipped.';
        }

        return $out ?: ['You completed the conversation.'];
    }

    private function pronunciationNotes(ConversationSession $session): array
    {
        return DB::table('pronunciation_errors')
            ->join('phonemes', 'phonemes.id', '=', 'pronunciation_errors.phoneme_id')
            ->where('pronunciation_errors.user_id', $session->user_id)
            ->whereNull('pronunciation_errors.resolved_at')
            ->orderByDesc('pronunciation_errors.recent_error_rate')
            ->limit(3)
            ->get(['phonemes.ipa', 'pronunciation_errors.recent_error_rate'])
            ->map(fn ($r) => [
                'phoneme' => $r->ipa,
                'error_rate' => round((float) $r->recent_error_rate, 3),
            ])->all();
    }

    private function vocabularySeen(ConversationSession $session): array
    {
        $aiText = $session->turns->where('speaker', 'ai')->pluck('text')->implode(' ');
        $words = collect(preg_split('/[^\p{L}\']+/u', mb_strtolower($aiText), -1, PREG_SPLIT_NO_EMPTY))
            ->filter(fn ($w) => mb_strlen($w) >= 6)->unique();

        return DB::table('vocabulary_items')
            ->whereIn('normalised', $words->take(60)->all())
            ->limit(6)->pluck('headword')->all();
    }

    private function recommendation($ranked): array
    {
        return collect($ranked)->map(fn ($e) => match ($e['type']) {
            'article' => 'Practise articles: a, an and the.',
            'preposition' => 'Practise prepositions in fixed phrases.',
            'word_order' => 'Practise word order in statements and questions.',
            'vocabulary_confusion' => 'Review the words you mixed up in this conversation.',
            'register' => 'Practise switching between formal and informal wording.',
            default => 'Review the corrections above and try this scenario again.',
        })->unique()->values()->all();
    }

    private function score(ConversationSession $session, int $errorCount, array $objectives): float
    {
        $turns = max(1, $session->turns->where('speaker', 'learner')->count());
        $blocked = $session->turns->where('blocked_communication', true)->count();
        $met = collect($objectives)->where('attempted', true)->count();
        $total = max(1, count($objectives));

        // Communication first: a blocked turn costs far more than a tidy slip.
        $communication = max(0, 1 - ($blocked / $turns));
        $accuracy = max(0, 1 - min(1, $errorCount / ($turns * 2)));
        $coverage = $met / $total;

        return round(100 * (0.5 * $communication + 0.3 * $accuracy + 0.2 * $coverage), 2);
    }
}
