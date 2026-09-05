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
        $spec = $this->prompts->lessonScene($lesson, $words);

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

        $result = $this->ai->scene(new MediaRequest(
            feature: 'lesson.scene.personalised',
            prompt: $spec['prompt'].($safe !== '' ? " Set the scene in a context involving: {$safe}." : ''),
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
