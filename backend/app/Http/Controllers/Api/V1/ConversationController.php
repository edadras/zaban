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
            'description' => $s->situation,
            'setting' => $s->setting,
            'situation' => $s->situation,
            'learner_role' => $s->learner_role,
            'cefr' => $s->cefrLevel?->code,
            'target_turns' => $s->target_turns,
            'objectives' => $s->objectives ?? [],
            'estimated_minutes' => max(5, (int) ceil(($s->target_turns ?? 10) / 2)),
            'is_locked' => false,
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

        return $this->created($this->present($session->load('turns', 'scenario')));
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

        $this->conversations->respond($session, $text, $speech);

        // Client expects a full session (turns + status), not a single reply.
        return $this->ok($this->present($session->fresh()->load('turns', 'scenario')));
    }

    public function finish(Request $request, ConversationSession $session)
    {
        $this->assertOwned($request, $session);
        $session = $this->conversations->finish($session);

        return $this->ok($this->present($session->load('turns', 'scenario')));
    }

    public function show(Request $request, ConversationSession $session)
    {
        $this->assertOwned($request, $session);

        return $this->ok($this->present($session->load('turns', 'scenario')));
    }

    private function present(ConversationSession $session): array
    {
        $session->loadMissing('scenario');

        $turns = ($session->relationLoaded('turns')
            ? $session->turns
            : $session->turns()->orderBy('position')->get()
        )->sortBy('position')->values();

        $summary = null;
        if ($session->status === 'completed') {
            $debrief = is_array($session->summary) ? $session->summary : [];
            $corrections = collect($debrief['corrections'] ?? [])->map(fn ($c) => [
                'type' => (string) ($c['type'] ?? 'grammar'),
                'note' => $c['note'] ?? null,
                'input' => $c['said'] ?? null,
                'correction' => $c['expected'] ?? null,
                'severity' => ! empty($c['blocked']) ? 4 : 2,
            ])->values()->all();

            $notes = array_values(array_filter([
                ...((array) ($debrief['went_well'] ?? [])),
                ...((array) ($debrief['pronunciation'] ?? [])),
                ...((array) ($debrief['recommended_practice'] ?? [])),
            ], fn ($n) => is_string($n) && $n !== ''));

            $met = is_array($session->objectives_met) ? array_values($session->objectives_met) : [];
            $all = is_array($session->scenario?->objectives) ? array_values($session->scenario->objectives) : [];
            $missed = array_values(array_diff($all, $met));

            $summary = [
                'overall_score' => $session->overall_score !== null ? (float) $session->overall_score : null,
                'objectives_met' => $met,
                'objectives_missed' => $missed,
                'notes' => $notes,
                'errors' => $corrections,
            ];
        }

        return [
            'id' => $session->id,
            'status' => $session->status,
            'mode' => $session->mode,
            'turn_count' => (int) $session->turn_count,
            'scenario_id' => $session->conversation_scenario_id ?? $session->scenario?->id,
            'scenario_title' => $session->scenario?->title,
            'scenario' => $session->scenario?->only(['id', 'title', 'setting', 'learner_role']),
            'turns' => $turns->map(fn (ConversationTurn $t) => [
                'id' => $t->id,
                'position' => (int) $t->position,
                'speaker' => $t->speaker,
                'text' => (string) $t->text,
                'observed_errors' => is_array($t->observed_errors) ? $t->observed_errors : [],
                'blocked_communication' => (bool) ($t->blocked_communication ?? false),
            ])->values()->all(),
            'summary' => $summary,
        ];
    }

    private function assertOwned(Request $request, ConversationSession $session): void
    {
        abort_unless($session->user_id === $request->user()->id, 404);
    }
}
