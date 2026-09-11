<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Scene\ScenePlayController;
use App\Models\Scene;
use App\Models\SceneBeat;
use App\Models\SceneSession;
use App\Models\SpeechAttempt;
use App\Services\Scene\SceneDirector;
use App\Services\Scene\ScenePresenter;
use Illuminate\Http\Request;

/**
 * Acted scenes.
 *
 * Conversation practice already had a character who talks back; this is the
 * same practice with a room around it. The endpoints are deliberately small -
 * list, open, answer one line, close - because the playing happens in the
 * client and the deciding happens in the service.
 */
class SceneController extends ApiController
{
    public function __construct(
        private SceneDirector $director,
        private ScenePresenter $presenter,
    ) {}

    /** Every scene a learner may play, newest situations first. */
    public function index(Request $request)
    {
        $scenes = Scene::query()
            ->with('cefrLevel', 'scenario')
            ->where('status', 'published')
            ->when($request->filled('scenario_id'), fn ($q) => $q->where(
                'conversation_scenario_id', $request->integer('scenario_id'),
            ))
            ->when($request->filled('environment'), fn ($q) => $q->where(
                'environment', $request->string('environment'),
            ))
            ->when($request->filled('cefr'), fn ($q) => $q->whereHas(
                'cefrLevel', fn ($l) => $l->where('code', $request->string('cefr')),
            ))
            ->orderBy('cefr_level_id')
            ->orderBy('title')
            ->get();

        return $this->ok($scenes->map(fn (Scene $s) => $this->presenter->card($s))->values());
    }

    /** One scene, without opening a run of it. */
    public function show(Request $request, Scene $scene)
    {
        abort_unless($scene->isPublished(), 404);

        return $this->ok($this->presenter->card($scene));
    }

    /**
     * Open a run.
     *
     * Returns the whole playable scene, so the client can start immediately
     * rather than fetching the script separately.
     */
    public function start(Request $request)
    {
        $data = $request->validate([
            'scene_id' => ['required', 'integer', 'exists:scenes,id'],
            'role' => ['nullable', 'string', 'max:24'],
            'mode' => ['nullable', 'in:watch,guided,roleplay'],
        ]);

        $scene = Scene::with('beats')->findOrFail($data['scene_id']);
        abort_unless($scene->isPublished(), 404);

        $session = $this->director->start(
            $request->user()->id,
            $scene,
            $data['role'] ?? null,
            $data['mode'] ?? 'guided',
        );

        return $this->created([
            ...$this->presenter->playable($scene, $session),
            // The signed link the client opens to actually watch and play it.
            ...ScenePlayController::links($session),
        ]);
    }

    /** Pick a run back up where it was left. */
    public function session(Request $request, SceneSession $session)
    {
        $this->assertOwned($request, $session);

        return $this->ok([
            ...$this->presenter->playable($session->scene()->with('beats')->firstOrFail(), $session),
            ...ScenePlayController::links($session),
        ]);
    }

    /**
     * The learner's go at one line.
     *
     * Whichever way they answered - microphone, keyboard or a choice - the
     * verdict comes back the same shape, with the words they missed and how
     * many tries are left.
     */
    public function answer(Request $request, SceneSession $session, SceneBeat $beat)
    {
        $this->assertOwned($request, $session);

        if ($session->status !== 'active') {
            return $this->fail('scene_closed', 'This scene has already finished.', 409);
        }
        if ($beat->scene_id !== $session->scene_id) {
            return $this->fail('beat_not_in_scene', 'That line belongs to another scene.', 422);
        }

        $data = $request->validate([
            'text' => ['nullable', 'string', 'max:1000'],
            'choice' => ['nullable', 'integer', 'min:0', 'max:20'],
            'speech_attempt_id' => ['nullable', 'integer', 'exists:speech_attempts,id'],
        ]);

        $speech = null;
        if (! empty($data['speech_attempt_id'])) {
            $speech = SpeechAttempt::findOrFail($data['speech_attempt_id']);
            abort_unless($speech->user_id === $request->user()->id, 403);
        }

        $kind = $this->director->interactionFor($session, $beat);

        if ($kind === 'choose' && ! isset($data['choice'])) {
            return $this->fail('no_choice', 'Choose one of the replies.', 422);
        }
        if ($kind !== 'choose' && ! ($data['text'] ?? null) && ! ($speech?->transcript)) {
            // A recording that has not come back from the recogniser is not a
            // wrong answer, and must not be counted as one.
            return $this->fail(
                'no_answer',
                $speech ? 'That recording has not been transcribed yet.' : 'Say or type the line first.',
                422,
            );
        }

        $verdict = $this->director->answer(
            $session,
            $beat,
            $data['text'] ?? null,
            $data['choice'] ?? null,
            $speech,
        );

        return $this->ok([
            'verdict' => $verdict,
            'session' => $this->presenter->session($session->fresh()),
        ]);
    }

    /** Close the run and hand back the debrief. */
    public function finish(Request $request, SceneSession $session)
    {
        $this->assertOwned($request, $session);

        $session = $this->director->finish($session);

        return $this->ok($this->presenter->session($session));
    }

    private function assertOwned(Request $request, SceneSession $session): void
    {
        abort_unless($session->user_id === $request->user()->id, 404);
    }
}
