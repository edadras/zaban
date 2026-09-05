<?php

namespace App\Services\Media;

use App\Models\Character;
use App\Models\Lesson;
use App\Models\LessonBlock;
use App\Models\MediaAsset;
use App\Models\MediaBrief;
use App\Models\VocabularySense;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Pulls finished generations into the project and attaches them to the content.
 *
 * Vendor result URLs expire, so a generation that is only referenced by its URL
 * is a generation that will disappear - this is the step that makes it ours.
 * Every import is checksummed and idempotent: re-running after a partial batch
 * re-attaches what is already on disk instead of re-downloading it, which
 * matters because the batches this handles are thousands of files long and will
 * be interrupted.
 */
class GeneratedMediaImporter
{
    public function __construct(private string $disk = 'local') {}

    /**
     * @param  array<int,string|array>  $entries  brief id => result URL, or a
     *                                            richer entry from the local runner
     * @return array{imported:int, skipped:int, failed:int, errors:array<int,string>}
     */
    public function importMany(array $entries, ?string $baseDir = null): array
    {
        $out = ['imported' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => []];

        foreach ($entries as $briefId => $entry) {
            [$url, $localFile, $skip] = $this->resolveEntry($entry, $baseDir);

            if ($skip !== null) {
                $out['skipped']++;

                continue;
            }

            if ($url === null && $localFile === null) {
                $out['failed']++;
                $out['errors'][$briefId] = 'Entry carries neither a URL nor a readable file.';

                continue;
            }
            $brief = MediaBrief::find($briefId);

            if (! $brief) {
                $out['failed']++;
                $out['errors'][$briefId] = 'No such brief.';

                continue;
            }

            if ($brief->status === MediaBrief::STATUS_IMPORTED && $brief->media_asset_id) {
                $out['skipped']++;

                continue;
            }

            try {
                $this->import($brief, $url, $localFile);
                $out['imported']++;
            } catch (\Throwable $e) {
                $brief->update([
                    'status' => MediaBrief::STATUS_FAILED,
                    'error' => $e->getMessage(),
                ]);
                $out['failed']++;
                $out['errors'][$briefId] = $e->getMessage();
            }
        }

        return $out;
    }

    /**
     * Normalise one results entry.
     *
     * The local runner writes richer entries than a bare URL - status, the file
     * it saved, its size - because a run of thousands is worth being able to
     * audit. Both shapes are accepted so results.json can come from either the
     * runner or a hand-written mapping.
     *
     * @return array{0:?string, 1:?string, 2:?string} url, local file, skip reason
     */
    private function resolveEntry(string|array $entry, ?string $baseDir): array
    {
        if (is_string($entry)) {
            return [$entry, null, null];
        }

        $status = $entry['status'] ?? 'ok';

        if (! in_array($status, ['ok', 'already_downloaded'], true)) {
            return [null, null, "runner reported status '{$status}'"];
        }

        $file = null;

        if (! empty($entry['file']) && $baseDir) {
            $candidate = rtrim($baseDir, '/').'/'.ltrim((string) $entry['file'], '/');

            // Prefer the file the runner already downloaded: provider URLs
            // expire, and a manifest imported a day late would otherwise fail
            // wholesale.
            if (is_file($candidate) && filesize($candidate) > 1024) {
                $file = $candidate;
            }
        }

        return [$entry['url'] ?? null, $file, null];
    }

    public function import(MediaBrief $brief, ?string $url, ?string $localFile = null): MediaAsset
    {
        $bytes = $localFile !== null
            ? (string) file_get_contents($localFile)
            : $this->fetch((string) $url);
        $checksum = hash('sha256', $bytes);

        // The same prompt can legitimately be rendered for two subjects; storing
        // by checksum means identical output is stored once and shared.
        $existing = MediaAsset::where('checksum', $checksum)->first();

        $asset = $existing ?: $this->store($brief, $bytes, $checksum);

        DB::transaction(function () use ($brief, $asset, $url) {
            $this->attach($brief, $asset);

            $brief->update([
                'status' => MediaBrief::STATUS_IMPORTED,
                'media_asset_id' => $asset->id,
                'result_url' => $url ?? $localFile,
                'error' => null,
                'generated_at' => $brief->generated_at ?? now(),
            ]);
        });

        return $asset;
    }

    private function fetch(string $url): string
    {
        $response = Http::timeout(120)->retry(3, 2000)->get($url);

        if (! $response->successful()) {
            throw new \RuntimeException("Download failed with HTTP {$response->status()}.");
        }

        $bytes = $response->body();

        if (strlen($bytes) < 1024) {
            throw new \RuntimeException('Downloaded file is too small to be an image.');
        }

        return $bytes;
    }

    private function store(MediaBrief $brief, string $bytes, string $checksum): MediaAsset
    {
        // Sharded by checksum prefix: 2,500+ files in one directory is a
        // filesystem problem, and the shard is stable across re-imports.
        $path = sprintf(
            'generated/%s/%s/%s.png',
            $brief->kind,
            substr($checksum, 0, 2),
            $checksum,
        );

        Storage::disk($this->disk)->put($path, $bytes);

        $size = @getimagesizefromstring($bytes);

        return MediaAsset::create([
            'disk' => $this->disk,
            'path' => $path,
            'type' => 'image',
            'mime' => $size['mime'] ?? 'image/png',
            'bytes' => strlen($bytes),
            'width' => $size[0] ?? null,
            'height' => $size[1] ?? null,
            'checksum' => $checksum,
            'origin' => 'generated',
            'copyright_status' => 'owned',
            'metadata' => [
                'brief_id' => $brief->id,
                'kind' => $brief->kind,
                'model' => $brief->model,
                'prompt' => $brief->prompt,
                'aspect_ratio' => $brief->aspect_ratio,
                'resolution' => $brief->resolution,
            ],
        ]);
    }

    /**
     * Wire the asset into the content so the client can actually reach it.
     * An imported asset nothing points at is invisible to the learner, which is
     * the failure mode this method exists to prevent.
     */
    private function attach(MediaBrief $brief, MediaAsset $asset): void
    {
        match ($brief->kind) {
            MediaBrief::KIND_LESSON_SCENE => $this->attachLessonScene($brief, $asset),
            MediaBrief::KIND_VOCABULARY_CARD => $this->attachVocabularyCard($brief, $asset),
            MediaBrief::KIND_CHARACTER_PORTRAIT => $this->attachCharacterPortrait($brief, $asset),
            default => throw new \RuntimeException("Don't know how to attach a {$brief->kind}."),
        };
    }

    private function attachLessonScene(MediaBrief $brief, MediaAsset $asset): void
    {
        $lesson = Lesson::find($brief->subject_id);

        if (! $lesson) {
            throw new \RuntimeException("Lesson {$brief->subject_id} no longer exists.");
        }

        $block = LessonBlock::firstOrNew([
            'lesson_id' => $lesson->id,
            'type' => 'image_scene',
        ]);

        $block->media_asset_id = $asset->id;
        $block->position ??= ((int) LessonBlock::where('lesson_id', $lesson->id)->max('position')) + 1;
        $block->save();
    }

    private function attachVocabularyCard(MediaBrief $brief, MediaAsset $asset): void
    {
        $sense = VocabularySense::find($brief->subject_id);

        if (! $sense) {
            throw new \RuntimeException("Vocabulary sense {$brief->subject_id} no longer exists.");
        }

        // The card illustrates the sense through the example that grounded it,
        // which is where the client already looks for sense-level media.
        $example = $sense->examples()->orderBy('position')->first();

        if ($example) {
            $example->update(['media_asset_id' => $asset->id]);
        }
    }

    private function attachCharacterPortrait(MediaBrief $brief, MediaAsset $asset): void
    {
        $character = Character::find($brief->subject_id);

        if (! $character) {
            throw new \RuntimeException("Character {$brief->subject_id} no longer exists.");
        }

        $character->forceFill([
            'avatar_media_asset_id' => $asset->id,
            // The canonical reference every later generation of this character
            // is anchored to.
            'reference_media_asset_id' => $asset->id,
        ])->save();
    }
}
