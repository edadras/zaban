<?php

namespace App\Support;

use App\Models\MediaAsset;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * Where a media file actually is.
 *
 * The corpus was ingested with repository-relative paths - `sources/audio/...`,
 * beside the books it came out of - while everything generated since is written
 * to a storage disk. Both are rows in `media_assets` with a `disk` and a `path`,
 * and only one of them can be found by asking the disk. Anything that opens a
 * media file therefore has to try both, and this is the one place that knows how.
 *
 * The order is deliberate: the disk first, so a file that has been moved into
 * storage wins over a stale copy left in the source tree.
 */
class MediaPath
{
    public static function disk(MediaAsset $asset): Filesystem
    {
        return Storage::disk($asset->disk === 'remote' ? config('filesystems.default') : $asset->disk);
    }

    /** True when the asset can be read through its own disk. */
    public static function onDisk(MediaAsset $asset): bool
    {
        try {
            return self::disk($asset)->exists($asset->path);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * An absolute path to the bytes, or null when there are none to be had.
     *
     * Only local storage has an absolute path at all; a remote disk returns
     * null here and has to be read as a stream instead.
     */
    public static function absolute(MediaAsset $asset): ?string
    {
        foreach (self::candidates($asset) as $candidate) {
            if ($candidate !== null && is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /** @return array<int,?string> */
    private static function candidates(MediaAsset $asset): array
    {
        $path = ltrim((string) $asset->path, '/');

        $onDisk = null;
        try {
            $disk = self::disk($asset);
            if ($disk->exists($path)) {
                $onDisk = $disk->path($path);
            }
        } catch (\Throwable) {
            // A remote or misconfigured disk simply has no local path.
        }

        return [
            $onDisk,
            storage_path('app/'.$path),
            storage_path('app/private/'.$path),
            // The corpus, where the ingestion left it: one level above the
            // Laravel application, in the repository that holds the sources.
            base_path('../'.$path),
            base_path($path),
        ];
    }
}
