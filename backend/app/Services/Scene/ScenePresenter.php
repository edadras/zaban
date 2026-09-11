<?php

namespace App\Services\Scene;

use App\Models\Character;
use App\Models\Scene;
use App\Models\SceneAttempt;
use App\Models\SceneBeat;
use App\Models\SceneSession;
use App\Services\Media\MediaPresenter;

/**
 * Turns a scene into something the player can shoot.
 *
 * Two rules shape everything here. The client is handed the whole scene up
 * front - the room, the cast, the camera cues and the audio windows - because a
 * conversation that stalls for a network round trip between two lines is not a
 * conversation. But it is never handed a line the learner is supposed to
 * produce: that one arrives when they have earned it, or when they have run out
 * of tries, and not before.
 */
class ScenePresenter
{
    public function __construct(
        private MediaPresenter $media,
        private SceneDirector $director,
    ) {}

    /** The scene as it appears in a list: enough to choose it, not to play it. */
    public function card(Scene $scene): array
    {
        $scene->loadMissing('cefrLevel', 'scenario');

        return [
            'id' => $scene->id,
            'slug' => $scene->slug,
            'title' => $scene->title,
            'title_fa' => $scene->title_fa,
            'situation' => $scene->situation,
            'situation_fa' => $scene->situation_fa,
            'environment' => $scene->environment,
            'cefr' => $scene->cefrLevel?->code,
            'scenario_id' => $scene->conversation_scenario_id,
            'scenario_setting' => $scene->scenario?->setting,
            'estimated_seconds' => (int) $scene->estimated_seconds,
            'objectives' => (array) ($scene->objectives ?? []),
            'roles' => collect($scene->cast ?? [])->map(fn ($c) => [
                'role' => $c['role'] ?? null,
                'name' => $c['name'] ?? null,
                'name_fa' => $c['name_fa'] ?? null,
                'playable' => (bool) ($c['playable'] ?? true),
            ])->values()->all(),
            'line_count' => $scene->beats()->count(),
            'vocabulary_count' => count((array) ($scene->vocabulary ?? [])),
        ];
    }

    /** The scene as it is played, for one particular run of it. */
    public function playable(Scene $scene, SceneSession $session): array
    {
        $scene->loadMissing('beats.audio', 'cefrLevel');
        $session->loadMissing('sceneAttempts');

        // One query for the cast rather than one per member.
        $slugs = collect($scene->cast ?? [])->pluck('character')->filter()->unique()->all();
        $characters = $slugs ? Character::whereIn('slug', $slugs)->get()->keyBy('slug') : collect();

        $unlocked = $this->unlockedBeatIds($session);

        return [
            'session' => $this->session($session),
            'scene' => [
                'id' => $scene->id,
                'slug' => $scene->slug,
                'title' => $scene->title,
                'title_fa' => $scene->title_fa,
                'situation' => $scene->situation,
                'situation_fa' => $scene->situation_fa,
                'environment' => $scene->environment,
                'light' => (float) $scene->light,
                'cefr' => $scene->cefrLevel?->code,
                'props' => array_values((array) ($scene->props ?? [])),
                'vocabulary' => array_values((array) ($scene->vocabulary ?? [])),
                'objectives' => array_values((array) ($scene->objectives ?? [])),
                'cast' => collect($scene->cast ?? [])->map(function (array $member) use ($characters) {
                    $character = $member['character'] ?? null ? $characters->get($member['character']) : null;

                    return [
                        'role' => $member['role'] ?? null,
                        'name' => $member['name'] ?? $character?->name,
                        'name_fa' => $member['name_fa'] ?? null,
                        'character' => $member['character'] ?? null,
                        'accent' => $character?->accent,
                        // The rig, when the cast member has one. Null is not a
                        // failure: the player has a built-in figure and uses it.
                        'model_url' => $character?->model_3d_status === 'ready' ? $character->model_3d_url : null,
                        'colour' => $member['colour'] ?? '#4f7cff',
                        'skin' => $member['skin'] ?? '#c68642',
                        'x' => (float) ($member['x'] ?? 0),
                        'z' => (float) ($member['z'] ?? 0),
                        'rotation' => (float) ($member['rotation'] ?? 0),
                        'expression' => $member['expression'] ?? 'neutral',
                        'playable' => (bool) ($member['playable'] ?? true),
                    ];
                })->values()->all(),
            ],
            'beats' => $scene->beats->map(fn (SceneBeat $beat) => $this->beat($beat, $session, $unlocked))->values()->all(),
        ];
    }

    public function session(SceneSession $session): array
    {
        return [
            'id' => $session->id,
            'scene_id' => $session->scene_id,
            'role' => $session->role,
            'mode' => $session->mode,
            'status' => $session->status,
            'position' => (int) $session->position,
            'attempts' => (int) $session->attempts,
            'cleared' => (int) $session->cleared,
            'score' => $session->score !== null ? (float) $session->score : null,
            'summary' => $session->summary,
            'max_tries' => SceneDirector::MAX_TRIES,
        ];
    }

    private function beat(SceneBeat $beat, SceneSession $session, array $unlocked): array
    {
        $interaction = $this->director->interactionFor($session, $beat);
        $open = $interaction !== 'watch' && ! in_array($beat->id, $unlocked, true);

        return [
            'id' => $beat->id,
            'position' => (int) $beat->position,
            'role' => $beat->role,
            // Withheld while the learner still owes this line.
            'text' => $open ? null : $beat->text,
            'translation_fa' => $beat->translation_fa,
            'camera' => $beat->camera,
            'animation' => $beat->animation,
            'gesture' => $beat->gesture,
            'expression' => $beat->expression,
            'interaction' => $interaction,
            'prompt' => $beat->prompt,
            'prompt_fa' => $beat->prompt_fa,
            'hint' => $beat->hint,
            'hint_fa' => $beat->hint_fa,
            'word_count' => count(preg_split('/\s+/u', trim($beat->text)) ?: []),
            'choices' => $interaction === 'choose'
                // Without which one is right: the answer is not sent to the
                // machine that is allowed to be wrong about it.
                ? collect((array) ($beat->choices ?? []))->map(fn ($c, $i) => [
                    'index' => $i,
                    'text' => $c['text'] ?? '',
                    'text_fa' => $c['text_fa'] ?? null,
                ])->values()->all()
                : null,
            'audio' => $this->audio($beat),
        ];
    }

    /**
     * The voice for one line: which recording, and where in it.
     *
     * The window matters as much as the file. The course's audio is one track
     * per exercise, so a line is a second and a half somewhere inside a
     * ninety-second recording, and the player seeks to it rather than playing
     * the lot.
     */
    private function audio(SceneBeat $beat): ?array
    {
        if (! $beat->audio_media_asset_id) {
            return null;
        }

        $media = $this->media->present($beat->audio);
        if (! $media) {
            return null;
        }

        return [
            ...$media,
            'start_ms' => $beat->audio_start_ms !== null ? (int) $beat->audio_start_ms : null,
            'end_ms' => $beat->audio_end_ms !== null ? (int) $beat->audio_end_ms : null,
            'method' => $beat->audio_method,
            'confidence' => $beat->audio_confidence,
            'reviewed' => $beat->audio_review_status === 'approved',
        ];
    }

    /**
     * Beats whose line the learner may now see: the ones they got right, and
     * the ones they ran out of tries on.
     *
     * @return array<int,int>
     */
    private function unlockedBeatIds(SceneSession $session): array
    {
        $byBeat = $session->sceneAttempts->groupBy('scene_beat_id');

        return $byBeat->filter(
            fn ($attempts) => $attempts->contains(fn (SceneAttempt $a) => $a->accepted)
                || $attempts->max('try_number') >= SceneDirector::MAX_TRIES,
        )->keys()->map(fn ($id) => (int) $id)->all();
    }
}
