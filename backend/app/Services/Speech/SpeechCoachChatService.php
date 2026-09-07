<?php

namespace App\Services\Speech;

use App\AI\AiOrchestrator;
use App\AI\Support\TextRequest;
use App\Models\LearnerProfile;
use App\Models\SpeechAttempt;
use App\Models\SpeechCoachMessage;
use App\Models\SpeechCoachSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Corrective coach chat: natural English conversation that still teaches
 * grammar, vocabulary and pronunciation on every learner turn.
 */
class SpeechCoachChatService
{
    private const SCHEMA = [
        'type' => 'object',
        'additionalProperties' => false,
        'required' => [
            'reply',
            'reply_fa',
            'corrected_learner',
            'grammar',
            'vocabulary',
            'pronunciation',
        ],
        'properties' => [
            'reply' => ['type' => 'string'],
            'reply_fa' => ['type' => 'string'],
            'corrected_learner' => ['type' => 'string'],
            'grammar' => [
                'type' => 'array',
                'maxItems' => 3,
                'items' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['en', 'fa'],
                    'properties' => [
                        'en' => ['type' => 'string'],
                        'fa' => ['type' => 'string'],
                    ],
                ],
            ],
            'vocabulary' => [
                'type' => 'array',
                'maxItems' => 3,
                'items' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['word', 'meaning_en', 'meaning_fa', 'example'],
                    'properties' => [
                        'word' => ['type' => 'string'],
                        'meaning_en' => ['type' => 'string'],
                        'meaning_fa' => ['type' => 'string'],
                        'example' => ['type' => 'string'],
                    ],
                ],
            ],
            'pronunciation' => [
                'type' => 'array',
                'maxItems' => 3,
                'items' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['en', 'fa'],
                    'properties' => [
                        'en' => ['type' => 'string'],
                        'fa' => ['type' => 'string'],
                    ],
                ],
            ],
        ],
    ];

    public function __construct(private AiOrchestrator $ai) {}

    public function start(int $userId): SpeechCoachSession
    {
        return DB::transaction(function () use ($userId) {
            $session = SpeechCoachSession::create([
                'user_id' => $userId,
                'status' => 'active',
                'turn_count' => 0,
            ]);

            $wantsFa = $this->wantsPersian($userId);
            $opening = 'Hi! Tell me about your day, or ask me anything. I will reply in English and gently correct grammar, words and pronunciation as we go.';
            $openingFa = 'سلام! درباره روزت بگو یا هر سوالی بپرس. من به انگلیسی جواب می‌دهم و در هر نوبت گرامر، واژه‌ها و تلفظ را هم اصلاح می‌کنم.';

            $this->addMessage($session, 'coach', $opening, $wantsFa ? $openingFa : $openingFa);

            return $session->fresh('messages');
        });
    }

    public function respond(
        SpeechCoachSession $session,
        string $text,
        ?SpeechAttempt $speech = null,
    ): SpeechCoachSession {
        $text = trim($text);
        if ($text === '') {
            return $session->fresh('messages');
        }

        DB::transaction(function () use ($session, $text, $speech) {
            $this->addMessage($session, 'learner', $text, null, null, null, $speech);
            $session->increment('turn_count');
        });

        $session->refresh()->load('messages');
        $coach = $this->generateCoachTurn($session, $text);

        $learnerTurn = $session->messages
            ->where('role', 'learner')
            ->sortByDesc('position')
            ->first();
        if ($learnerTurn && ! empty($coach['corrected_learner'])) {
            $learnerTurn->update(['corrected_text' => $coach['corrected_learner']]);
        }

        $this->addMessage(
            $session,
            'coach',
            $coach['reply'],
            $coach['reply_fa'],
            null,
            $coach['coaching'],
        );

        return $session->fresh('messages');
    }

    public function finish(SpeechCoachSession $session): SpeechCoachSession
    {
        $session->update([
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        return $session->fresh('messages');
    }

    /** @return array{reply:string,reply_fa:string,corrected_learner:?string,coaching:array} */
    private function generateCoachTurn(SpeechCoachSession $session, string $learnerText): array
    {
        $level = LearnerProfile::with('cefrLevel')
            ->where('user_id', $session->user_id)
            ->first()?->cefrLevel?->code ?? 'B1';

        $history = $session->messages
            ->map(fn (SpeechCoachMessage $m) => strtoupper($m->role).': '.$m->text)
            ->implode("\n");

        $system = implode(' ', [
            'You are a friendly English conversation coach for Persian-speaking learners.',
            "Learner CEFR level: {$level}.",
            'Have a natural English conversation (reply field).',
            'Also teach on every turn: grammar fixes, useful vocabulary meanings, and pronunciation tips.',
            'corrected_learner: a natural corrected version of what they just said (or empty string if already good).',
            'reply_fa: accurate Persian translation of your English reply.',
            'Keep reply to 1–3 short sentences so the learner keeps talking.',
            'If there is nothing to correct, return empty arrays for grammar/vocabulary/pronunciation.',
        ]);

        $result = $this->ai->text(new TextRequest(
            feature: 'speech.coach_chat',
            system: $system,
            prompt: "Conversation so far:\n{$history}\n\nLearner just said: \"{$learnerText}\"\nRespond with coaching JSON.",
            schema: self::SCHEMA,
            temperature: 0.55,
            maxTokens: 1100,
            userId: $session->user_id,
            cacheable: false,
            metadata: ['speech_coach_session_id' => $session->id],
        ));

        if (! $result->ok || ! is_array($result->json)) {
            Log::warning('speech.coach_chat.failed', [
                'session_id' => $session->id,
                'error' => $result->error,
            ]);

            return [
                'reply' => 'Thanks — keep going. What happened next?',
                'reply_fa' => 'ممنون — ادامه بده. بعدش چه شد؟',
                'corrected_learner' => $learnerText,
                'coaching' => [
                    'grammar' => [],
                    'vocabulary' => [],
                    'pronunciation' => [],
                ],
            ];
        }

        $json = $result->json;

        return [
            'reply' => trim((string) ($json['reply'] ?? 'Got it — tell me more.')),
            'reply_fa' => trim((string) ($json['reply_fa'] ?? '')),
            'corrected_learner' => trim((string) ($json['corrected_learner'] ?? '')) ?: null,
            'coaching' => [
                'grammar' => array_values((array) ($json['grammar'] ?? [])),
                'vocabulary' => array_values((array) ($json['vocabulary'] ?? [])),
                'pronunciation' => array_values((array) ($json['pronunciation'] ?? [])),
            ],
        ];
    }

    private function addMessage(
        SpeechCoachSession $session,
        string $role,
        string $text,
        ?string $textFa = null,
        ?string $corrected = null,
        ?array $coaching = null,
        ?SpeechAttempt $speech = null,
    ): SpeechCoachMessage {
        $position = (int) SpeechCoachMessage::where('speech_coach_session_id', $session->id)->max('position') + 1;

        return SpeechCoachMessage::create([
            'speech_coach_session_id' => $session->id,
            'position' => $position,
            'role' => $role,
            'text' => $text,
            'text_fa' => $textFa,
            'corrected_text' => $corrected,
            'coaching' => $coaching,
            'speech_attempt_id' => $speech?->id,
        ]);
    }

    private function wantsPersian(int $userId): bool
    {
        $locale = User::where('id', $userId)->value('locale');

        return is_string($locale) && str_starts_with(strtolower($locale), 'fa');
    }
}
