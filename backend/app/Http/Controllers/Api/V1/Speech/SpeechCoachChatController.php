<?php

namespace App\Http\Controllers\Api\V1\Speech;

use App\Http\Controllers\Api\V1\ApiController;
use App\Models\SpeechAttempt;
use App\Models\SpeechCoachMessage;
use App\Models\SpeechCoachSession;
use App\Services\Speech\SpeechCoachChatService;
use Illuminate\Http\Request;

class SpeechCoachChatController extends ApiController
{
    public function __construct(private SpeechCoachChatService $chat) {}

    public function start(Request $request)
    {
        $session = $this->chat->start($request->user()->id);

        return $this->created($this->present($session));
    }

    public function show(Request $request, SpeechCoachSession $session)
    {
        $this->assertOwned($request, $session);

        return $this->ok($this->present($session->load('messages')));
    }

    public function respond(Request $request, SpeechCoachSession $session)
    {
        $this->assertOwned($request, $session);

        if ($session->status !== 'active') {
            return $this->fail('coach_chat_closed', 'This coach chat has already finished.', 409);
        }

        $data = $request->validate([
            'text' => ['required_without:speech_attempt_id', 'nullable', 'string', 'max:2000'],
            'speech_attempt_id' => ['nullable', 'integer', 'exists:speech_attempts,id'],
        ]);

        $speech = null;
        $text = $data['text'] ?? null;

        if (! empty($data['speech_attempt_id'])) {
            $speech = SpeechAttempt::findOrFail($data['speech_attempt_id']);
            abort_unless($speech->user_id === $request->user()->id, 403);
            $text = $speech->transcript ?: $text;
        }

        if (! $text) {
            return $this->fail('no_transcript', 'That recording has not been transcribed yet.', 422);
        }

        $session = $this->chat->respond($session, $text, $speech);

        return $this->ok($this->present($session));
    }

    public function finish(Request $request, SpeechCoachSession $session)
    {
        $this->assertOwned($request, $session);
        $session = $this->chat->finish($session);

        return $this->ok($this->present($session));
    }

    private function present(SpeechCoachSession $session): array
    {
        $messages = ($session->relationLoaded('messages')
            ? $session->messages
            : $session->messages()->orderBy('position')->get()
        )->sortBy('position')->values();

        return [
            'id' => $session->id,
            'status' => $session->status,
            'turn_count' => (int) $session->turn_count,
            'messages' => $messages->map(fn (SpeechCoachMessage $m) => [
                'id' => $m->id,
                'position' => (int) $m->position,
                'role' => $m->role,
                'text' => (string) $m->text,
                'text_fa' => $m->text_fa,
                'corrected_text' => $m->corrected_text,
                'coaching' => $m->coaching,
                'speech_attempt_id' => $m->speech_attempt_id,
            ])->values()->all(),
        ];
    }

    private function assertOwned(Request $request, SpeechCoachSession $session): void
    {
        abort_unless($session->user_id === $request->user()->id, 404);
    }
}
