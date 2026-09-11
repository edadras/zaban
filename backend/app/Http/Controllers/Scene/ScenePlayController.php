<?php

namespace App\Http\Controllers\Scene;

use App\Http\Controllers\Controller;
use App\Models\SceneBeat;
use App\Models\SceneSession;
use App\Models\SpeechAttempt;
use App\Services\Scene\SceneDirector;
use App\Services\Scene\ScenePresenter;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * Serving the player itself.
 *
 * The player is a web page, not an API client, and it runs in two places: a web
 * view inside the app, and a browser tab. Neither can be handed the learner's
 * bearer token - the first would have to inject it into a page, the second
 * would leave it in a URL bar - so the page is reached through a signed link
 * instead, and every call it makes carries the same signature.
 *
 * What that buys and what it costs, plainly: the link is good for one run of
 * one scene, for two hours, and it authorises nothing else. Anyone holding it
 * can play that scene as that learner until it expires. That is the same trade
 * the media links already make, and it is a smaller surface than a token.
 */
class ScenePlayController extends Controller
{
    private const LINK_MINUTES = 120;

    public function __construct(
        private SceneDirector $director,
        private ScenePresenter $presenter,
    ) {}

    /** The signed links a client needs to open a run of a scene. */
    public static function links(SceneSession $session): array
    {
        $until = now()->addMinutes(self::LINK_MINUTES);

        return [
            'player_url' => URL::temporarySignedRoute('scene.play', $until, ['session' => $session->id]),
            'expires_in' => self::LINK_MINUTES * 60,
        ];
    }

    public function play(Request $request, SceneSession $session)
    {
        abort_unless($request->hasValidSignature(), 403, 'This scene link has expired.');

        $scene = $session->scene()->with('beats.audio', 'cefrLevel')->firstOrFail();
        $until = now()->addMinutes(self::LINK_MINUTES);

        $bootstrap = $this->presenter->playable($scene, $session);

        /*
         * The modelled cast and rooms, when they have been built and put in
         * place. Absent, the player draws the figures and the room itself, so
         * an installation without the kit still plays every scene.
         */
        $bootstrap['kit'] = collect(config('scene.kit', []))
            ->map(fn (?string $path) => $path && file_exists(public_path(ltrim($path, '/')))
                ? asset($path)
                : null)
            ->all();

        $bootstrap['endpoints'] = [
            'state' => URL::temporarySignedRoute('scene.state', $until, ['session' => $session->id]),
            'answer' => URL::temporarySignedRoute('scene.answer', $until, ['session' => $session->id]),
            'finish' => URL::temporarySignedRoute('scene.finish', $until, ['session' => $session->id]),
        ];

        return view('scene.play', [
            'scene' => $scene,
            'bootstrap' => $bootstrap,
        ]);
    }

    public function state(Request $request, SceneSession $session)
    {
        abort_unless($request->hasValidSignature(), 403);

        $scene = $session->scene()->with('beats.audio')->firstOrFail();

        return ApiResponse::ok($this->presenter->playable($scene, $session));
    }

    public function answer(Request $request, SceneSession $session)
    {
        abort_unless($request->hasValidSignature(), 403);

        if ($session->status !== 'active') {
            return ApiResponse::error('scene_closed', 'This scene has already finished.', 409);
        }

        $data = $request->validate([
            'beat_id' => ['required', 'integer'],
            'text' => ['nullable', 'string', 'max:1000'],
            'choice' => ['nullable', 'integer', 'min:0', 'max:20'],
            'speech_attempt_id' => ['nullable', 'integer'],
        ]);

        $beat = SceneBeat::where('scene_id', $session->scene_id)->findOrFail($data['beat_id']);

        $speech = null;
        if (! empty($data['speech_attempt_id'])) {
            $speech = SpeechAttempt::find($data['speech_attempt_id']);
            // The link proves the session, and the session names the learner;
            // a recording belonging to anyone else is simply not used.
            if ($speech && $speech->user_id !== $session->user_id) {
                $speech = null;
            }
        }

        $kind = $this->director->interactionFor($session, $beat);

        if ($kind === 'choose' && ! isset($data['choice'])) {
            return ApiResponse::error('no_choice', 'Choose one of the replies.', 422);
        }
        if ($kind !== 'choose' && ! ($data['text'] ?? null) && ! ($speech?->transcript)) {
            return ApiResponse::error('no_answer', 'Say or type the line first.', 422);
        }

        $verdict = $this->director->answer(
            $session,
            $beat,
            $data['text'] ?? null,
            $data['choice'] ?? null,
            $speech,
        );

        return ApiResponse::ok([
            'verdict' => $verdict,
            'session' => $this->presenter->session($session->fresh()),
        ]);
    }

    public function finish(Request $request, SceneSession $session)
    {
        abort_unless($request->hasValidSignature(), 403);

        return ApiResponse::ok($this->presenter->session($this->director->finish($session)));
    }
}
