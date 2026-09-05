<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\ConversationScenario;
use App\Models\ConversationSession;
use App\Models\ConversationTurn;
use App\Models\SpeechAttempt;
use App\Services\Conversation\ConversationService;
use Illuminate\Http\Request;

class ConversationController extends ApiController
{
    public function __construct(private ConversationService $conversations) {}

    public function scenarios(Request $request)
    {
        $scenarios = ConversationScenario::with('cefrLevel')
            ->when($request->filled('setting'), fn ($q) => $q->where('setting', $request->string('setting')))
            ->orderBy('title')
            ->get();

        return $this->ok($scenarios->map(fn (ConversationScenario $s) => [
            'id' => $s->id,
            'slug' => $s->slug,
            'title' => $s->title,
            'setting' => $s->setting,
            'situation' => $s->situation,
            'learner_role' => $s->learner_role,
            'cefr' => $s->cefrLevel?->code,
            'target_turns' => $s->target_turns,
            'objectives' => $s->objectives,
        ])->values());
    }

    public function start(Request $request)
    {
        $data = $request->validate([
            'scenario_id' => ['required', 'integer', 'exists:conversation_scenarios,id'],
            'mode' => ['nullable', 'in:voice,text,mixed'],
        ]);

        $scenario = ConversationScenario::findOrFail($data['scenario_id']);
        $session = $this->conversations->start($request->user()->id, $scenario, $data['mode'] ?? 'voice');

        return $this->created($this->present($session));
    }

    public function respond(Request $request, ConversationSession $session)
    {
        $this->assertOwned($request, $session);

        if ($session->status !== 'active') {
            return $this->fail('conversation_closed', 'This conversation has already finished.', 409);
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

        $reply = $this->conversations->respond($session, $text, $speech);

        return $this->ok([
            'reply' => [
                'position' => $reply->position,
                'speaker' => $reply->speaker,
                'text' => $reply->text,
            ],
            // Deliberately absent mid-conversation: observed errors. Corrections
            // arrive in the debrief so the learner keeps talking (spec 26).
            'turn_count' => $session->fresh()->turn_count,
        ]);
    }

    public function finish(Request $request, ConversationSession $session)
    {
        $this->assertOwned($request, $session);
        $session = $this->conversations->finish($session);

        return $this->ok([
            'id' => $session->id,
            'status' => $session->status,
            'overall_score' => $session->overall_score !== null ? (float) $session->overall_score : null,
            'objectives_met' => $session->objectives_met,
            'debrief' => $session->summary,
        ]);
    }

    public function show(Request $request, ConversationSession $session)
    {
        $this->assertOwned($request, $session);

        return $this->ok($this->present($session->load('turns')));
    }

    private function present(ConversationSession $session): array
    {
        return [
            'id' => $session->id,
            'status' => $session->status,
            'mode' => $session->mode,
            'turn_count' => $session->turn_count,
            'scenario' => $session->scenario?->only(['id', 'title', 'setting', 'learner_role']),
            'turns' => $session->relationLoaded('turns')
                ? $session->turns->sortBy('position')->map(fn (ConversationTurn $t) => [
                    'position' => $t->position,
                    'speaker' => $t->speaker,
                    'text' => $t->text,
                ])->values()
                : $session->turns()->orderBy('position')->get()
                    ->map(fn (ConversationTurn $t) => [
                        'position' => $t->position,
                        'speaker' => $t->speaker,
                        'text' => $t->text,
                    ])->values(),
        ];
    }

    private function assertOwned(Request $request, ConversationSession $session): void
    {
        abort_unless($session->user_id === $request->user()->id, 404);
    }
}
