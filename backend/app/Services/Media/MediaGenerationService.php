<?php

namespace App\Services\Media;

use App\AI\AiOrchestrator;
use App\AI\Support\MediaRequest;
use App\Models\Lesson;
use App\Models\LessonBlock;
use App\Models\MediaAsset;
use Illuminate\Support\Facades\DB;

/**
 * Produces lesson media through the orchestrator.
 *
 * The reuse policy (spec 18) is the whole point: artwork for a standard lesson
 * is generated once and shared by every learner who takes it. Only genuinely
 * personalised media - a scene built around one learner's own interests - opts
 * out of the cache, because that is the only case where per-learner generation
 * is worth paying for.
 */
class MediaGenerationService
{
    public function __construct(
        private AiOrchestrator $ai,
        private PromptBuilder $prompts,
    ) {}

    /**
     * Give a lesson a scene image if it has none.
     *
     * @return array{status:string, media_asset_id?:int, reason?:string}
     */
    public function ensureLessonScene(Lesson $lesson, bool $force = false): array
    {
        $existing = $lesson->blocks()->where('type', 'image_scene')->first();
        if ($existing && ! $force) {
            return ['status' => 'exists', 'media_asset_id' => $existing->media_asset_id];
        }

        $words = $lesson->concepts()->pluck('label')->take(6)->all();
        $spec = $this->prompts->forModel(
            $this->prompts->lessonScene($lesson, $words),
            $this->modelFor('scene'),
        );

        $result = $this->ai->scene(new MediaRequest(
            feature: 'lesson.scene',
            prompt: $spec['prompt'],
            negativePrompt: $spec['negative'],
            aspectRatio: $spec['aspect_ratio'],
            metadata: ['lesson_id' => $lesson->id],
            // Shared lesson artwork: generate once, reuse for everyone.
            cacheable: true,
        ));

        if (! $result->ok) {
            return ['status' => 'failed', 'reason' => $result->error];
        }

        $asset = MediaAsset::where('path', $result->localPath ?? $result->url)->first();
        if (! $asset) {
            return ['status' => 'failed', 'reason' => 'Generation succeeded but no asset was stored.'];
        }

        LessonBlock::updateOrCreate(
            ['lesson_id' => $lesson->id, 'type' => 'image_scene', 'position' => 100],
            [
                'media_asset_id' => $asset->id,
                'config' => ['origin' => 'ai_generated', 'prompt' => $spec['prompt']],
                'estimated_seconds' => 20,
            ],
        );

        return ['status' => 'generated', 'media_asset_id' => $asset->id];
    }

    /**
     * A scene personalised to one learner. Not cached across learners, because
     * the personalisation is the point - but still charged against their limits.
     */
    public function personalisedScene(Lesson $lesson, int $userId, array $interests): array
    {
        $spec = $this->prompts->lessonScene($lesson, $lesson->concepts()->pluck('label')->take(4)->all());
        $safe = collect($interests)->take(3)->implode(', ');

        if ($safe !== '') {
            $spec['prompt'] .= " Set the scene in a context involving: {$safe}.";
        }
        $spec = $this->prompts->forModel($spec, $this->modelFor('scene'));

        $result = $this->ai->scene(new MediaRequest(
            feature: 'lesson.scene.personalised',
            prompt: $spec['prompt'],
            negativePrompt: $spec['negative'],
            aspectRatio: $spec['aspect_ratio'],
            userId: $userId,
            metadata: ['lesson_id' => $lesson->id],
            cacheable: false,
        ));

        return $result->ok
            ? ['status' => 'generated', 'url' => $result->url, 'path' => $result->localPath]
            : ['status' => 'failed', 'reason' => $result->error];
    }

    /**
     * A portrait of a recurring character, anchored to its stable identity so it
     * is recognisably the same person every time.
     */
    public function characterPortrait(\App\Models\Character $character, bool $force = false): array
    {
        if ($character->reference_media_asset_id && ! $force) {
            return ['status' => 'exists', 'media_asset_id' => $character->reference_media_asset_id];
        }

        $spec = $this->prompts->forModel(
            $this->prompts->characterPortrait(
                $character->name,
                $character->persona ?? 'a friendly recurring character in a language course',
                $character->appearance_prompt,
            ),
            $this->modelFor('character'),
        );

        $result = $this->ai->character(new MediaRequest(
            feature: 'character.portrait',
            prompt: $spec['prompt'],
            negativePrompt: $spec['negative'],
            aspectRatio: $spec['aspect_ratio'],
            referenceImageUrl: $character->referenceImage?->path,
            soulId: $character->soul_id,
            metadata: ['character_id' => $character->id],
            cacheable: true,
        ));

        if (! $result->ok) {
            return ['status' => 'failed', 'reason' => $result->error];
        }

        $asset = MediaAsset::where('path', $result->localPath ?? $result->url)->first();
        if ($asset) {
            // The first portrait becomes the reference every later generation is
            // anchored to, so the cast stays stable even without a Soul id.
            $character->update(['reference_media_asset_id' => $asset->id]);
        }

        return ['status' => 'generated', 'media_asset_id' => $asset?->id];
    }

    /**
     * A spoken line delivered by a character: their voice, their face.
     *
     * This is the cheap route to a talking cast - a still portrait plus
     * synthesised speech - and it deliberately does not generate video. The
     * video step is the expensive one and is left to the caller to decide on.
     */
    public function characterLine(\App\Models\Character $character, string $text): array
    {
        $portrait = $this->characterPortrait($character);
        if ($portrait['status'] === 'failed') {
            return $portrait;
        }

        $audio = $this->ai->audio(new MediaRequest(
            feature: 'character.line',
            prompt: $text,
            voice: $character->voice_id,
            metadata: ['character_id' => $character->id],
            // The same character saying the same line is the same asset.
            cacheable: true,
        ));

        return [
            'status' => $audio->ok ? 'generated' : 'failed',
            'portrait_media_asset_id' => $portrait['media_asset_id'] ?? null,
            'audio_path' => $audio->localPath,
            'reason' => $audio->ok ? null : $audio->error,
        ];
    }

    /** Model pronunciation audio for a term, when no recording exists. */
    public function ensurePronunciationAudio(string $text, ?string $voice = null): array
    {
        $result = $this->ai->audio(new MediaRequest(
            feature: 'pronunciation.tts',
            prompt: $text,
            voice: $voice,
            cacheable: true,
        ));

        return $result->ok
            ? ['status' => 'generated', 'path' => $result->localPath, 'url' => $result->url]
            : ['status' => 'failed', 'reason' => $result->error];
    }

    /** Lessons that still have no artwork, cheapest-to-fix first. */
    /**
     * Which model will actually render this kind of request. Prompt shaping
     * depends on it, so it is resolved from the same config the provider uses
     * rather than guessed.
     */
    public function modelFor(string $kind): string
    {
        return (string) config("ai.providers.higgsfield.models.{$kind}", 'gpt_image_2');
    }

    public function lessonsMissingArtwork(int $limit = 100)
    {
        return Lesson::query()
            ->whereDoesntHave('blocks', fn ($q) => $q->where('type', 'image_scene'))
            ->whereHas('concepts')
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    /** What generating the outstanding artwork would cost, before spending it. */
    public function estimateBacklog(): array
    {
        $missing = Lesson::whereDoesntHave('blocks', fn ($q) => $q->where('type', 'image_scene'))
            ->whereHas('concepts')->count();

        $recent = DB::table('ai_requests')
            ->where('feature', 'lesson.scene')->where('status', 'succeeded')
            ->whereNotNull('credits_used')
            ->avg('credits_used');

        return [
            'lessons_without_artwork' => $missing,
            'observed_credits_per_image' => $recent !== null ? round((float) $recent, 3) : null,
            'estimated_credits' => $recent !== null ? round($missing * (float) $recent, 2) : null,
            'note' => $recent === null
                ? 'No completed generations yet, so cost per image is unknown.'
                : 'Estimate based on observed cost of previous generations.',
        ];
    }
}
