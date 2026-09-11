<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Book artwork that reached the lessons as a grey rectangle or a negative.
 *
 * Two faults in the same pipeline, both visible only once a lesson is opened:
 *
 * The Advanced book is a vector PDF and draws its tinted panels and drop
 * shadows as image objects. Forty of them cleared the extractor's size
 * threshold and were hung on lessons as artwork, so a learner reached a LOOK
 * step, was asked to look, and was shown a blank grey box.
 *
 * The book's real photographs are Adobe CMYK JPEGs, whose channels are stored
 * inverted. Nothing downstream can read that - a browser will not decode Adobe
 * CMYK at all, and a decoder that tries renders the negative. 154 pictures were
 * in that state.
 *
 * `tools/extract_images.py` now rejects an image that barely varies (page scans
 * excepted; a nearly blank page is still the page) and writes everything in
 * sRGB. This brings the catalogue into line with the manifest that run
 * produced: blocks pointing at something that is no longer artwork are removed,
 * and the rows that remain take the size the corrected file has.
 *
 * It also puts the bytes on the disk the app reads from. The extractor writes
 * into the working tree, and nothing had ever copied the result across, so 144
 * of these blocks had a row and no file behind it.
 */
return new class extends Migration
{
    public function up(): void
    {
        $manifest = $this->manifest();
        if ($manifest === []) {
            return;
        }

        $this->placeFilesOnTheMediaDisk($manifest);

        $assets = DB::table('media_assets')
            ->where('origin', 'ingested')
            ->where('path', 'like', 'sources/images/%')
            ->get(['id', 'path']);

        foreach ($assets as $asset) {
            $entry = $manifest[$asset->path] ?? null;

            if ($entry === null) {
                $this->retire($asset->id);

                continue;
            }

            DB::table('media_assets')->where('id', $asset->id)->update([
                'bytes' => $entry['bytes'] ?? null,
                'width' => $entry['width'] ?? null,
                'height' => $entry['height'] ?? null,
                'mime' => str_ends_with($asset->path, '.png') ? 'image/png' : 'image/jpeg',
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Not reversible, and deliberately so.
     *
     * What it removes is a block showing a blank panel and a row describing a
     * file that no longer exists. Putting either back would only put the empty
     * frame back in front of a learner; `content:import` rebuilds both from the
     * manifest if the manifest ever says they are artwork again.
     */
    public function down(): void {}

    /** @return array<string, array<string, mixed>> path => manifest entry */
    private function manifest(): array
    {
        $path = base_path('..').'/docs/data/images.json';
        if (! is_file($path)) {
            return [];
        }

        $books = json_decode((string) file_get_contents($path), true);
        if (! is_array($books)) {
            return [];
        }

        $byPath = [];
        foreach ($books as $book) {
            foreach ($book['images'] ?? [] as $image) {
                if (isset($image['path'])) {
                    $byPath[$image['path']] = $image;
                }
            }
        }

        return $byPath;
    }

    /** @param  array<string, array<string, mixed>>  $manifest */
    private function placeFilesOnTheMediaDisk(array $manifest): void
    {
        $disk = Storage::disk('local');

        foreach (array_keys($manifest) as $path) {
            $source = base_path('..').'/'.$path;
            if (! is_file($source)) {
                continue;
            }

            if ($disk->fileExists($path) && $disk->size($path) === filesize($source)) {
                continue;
            }

            $handle = fopen($source, 'rb');
            if ($handle === false) {
                continue;
            }

            $disk->writeStream($path, $handle);
            fclose($handle);
        }
    }

    private function retire(int $assetId): void
    {
        DB::table('lesson_blocks')
            ->where('type', 'image_scene')
            ->where('media_asset_id', $assetId)
            ->delete();

        DB::table('source_pages')
            ->where('page_image_media_asset_id', $assetId)
            ->update(['page_image_media_asset_id' => null]);

        DB::table('media_assets')->where('id', $assetId)->update(['deleted_at' => now()]);
    }
};
