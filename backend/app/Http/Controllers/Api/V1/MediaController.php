<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\MediaAsset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Media delivery.
 *
 * Lesson blocks reference assets by id, never by path, so the client cannot
 * enumerate storage and the underlying layout can change without breaking any
 * stored content. Playback goes through a short-lived signed URL: the audio and
 * artwork are licensed course material, so they must not be a permanently
 * guessable public link (spec 46).
 */
class MediaController extends ApiController
{
    /** How long a playback link stays valid. Long enough to finish a track. */
    private const LINK_TTL_MINUTES = 60;

    /** Resolve one asset to a playable URL. */
    public function show(Request $request, MediaAsset $media)
    {
        return $this->ok($this->present($media));
    }

    /**
     * Resolve many at once. A lesson can reference a dozen assets and a round
     * trip each would make the first screen crawl.
     */
    public function batch(Request $request)
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'max:100'],
            'ids.*' => ['integer'],
        ]);

        $assets = MediaAsset::whereIn('id', $data['ids'])->get();

        return $this->ok(
            $assets->mapWithKeys(fn (MediaAsset $m) => [$m->id => $this->present($m)]),
            ['requested' => count($data['ids']), 'found' => $assets->count()],
        );
    }

    /**
     * Stream the bytes. Reached only through a signed link, and supports range
     * requests so audio can be scrubbed rather than only played from the start.
     */
    public function stream(Request $request, MediaAsset $media): StreamedResponse
    {
        abort_unless($request->hasValidSignature(), 403, 'This media link has expired.');

        $disk = Storage::disk($media->disk === 'remote' ? config('filesystems.default') : $media->disk);
        abort_unless($disk->exists($media->path), 404, 'Media file not found.');

        $size = $disk->size($media->path);
        $stream = $disk->readStream($media->path);

        [$start, $end, $status] = $this->range($request, $size);
        $length = $end - $start + 1;

        $headers = [
            'Content-Type' => $media->mime,
            'Content-Length' => $length,
            'Accept-Ranges' => 'bytes',
            // Signed links already expire; caching privately for the link's life
            // avoids re-downloading a track the learner replays.
            'Cache-Control' => 'private, max-age='.(self::LINK_TTL_MINUTES * 60),
        ];
        if ($status === 206) {
            $headers['Content-Range'] = "bytes {$start}-{$end}/{$size}";
        }

        return response()->stream(function () use ($stream, $start, $length) {
            if ($start > 0) {
                fseek($stream, $start);
            }
            $remaining = $length;
            while ($remaining > 0 && ! feof($stream)) {
                $chunk = fread($stream, (int) min(8192, $remaining));
                if ($chunk === false) {
                    break;
                }
                echo $chunk;
                $remaining -= strlen($chunk);
                flush();
            }
            fclose($stream);
        }, $status, $headers);
    }

    private function present(MediaAsset $media): array
    {
        return [
            'id' => $media->id,
            'type' => $media->type,
            'mime' => $media->mime,
            'width' => $media->width,
            'height' => $media->height,
            'duration_ms' => $media->duration_ms,
            'url' => URL::temporarySignedRoute(
                'media.stream',
                now()->addMinutes(self::LINK_TTL_MINUTES),
                ['media' => $media->id],
            ),
            'expires_in' => self::LINK_TTL_MINUTES * 60,
        ];
    }

    /** @return array{0:int,1:int,2:int} start, end, status */
    private function range(Request $request, int $size): array
    {
        $header = $request->header('Range');
        if (! $header || ! preg_match('/bytes=(\d*)-(\d*)/', $header, $m)) {
            return [0, max(0, $size - 1), 200];
        }

        $start = $m[1] === '' ? 0 : (int) $m[1];
        $end = $m[2] === '' ? $size - 1 : (int) $m[2];
        $start = max(0, min($start, max(0, $size - 1)));
        $end = max($start, min($end, max(0, $size - 1)));

        return [$start, $end, 206];
    }
}
