<?php

namespace App\AI\Providers;

use App\AI\Contracts\AiMediaProviderInterface;
use App\AI\Support\MediaRequest;
use App\AI\Support\MediaResult;
use Illuminate\Support\Facades\Storage;

/**
 * Last-resort image fallback.
 *
 * Renders a neutral captioned card so a lesson still lays out correctly when no
 * image provider is reachable. It is deliberately plain and is marked as a
 * placeholder in metadata so the admin review queue can find and replace it -
 * it must never be mistaken for generated teaching artwork.
 */
class PlaceholderImageProvider implements AiMediaProviderInterface
{
    public function code(): string
    {
        return 'placeholder';
    }

    public function capabilities(): array
    {
        return ['image'];
    }

    public function isAvailable(): bool
    {
        return extension_loaded('gd');
    }

    public function generateImage(MediaRequest $r): MediaResult
    {
        [$w, $h] = match ($r->aspectRatio) {
            '1:1' => [768, 768],
            '9:16' => [576, 1024],
            '4:3' => [1024, 768],
            default => [1024, 576],
        };

        $img = imagecreatetruecolor($w, $h);
        $bg = imagecolorallocate($img, 24, 24, 27);
        $fg = imagecolorallocate($img, 161, 161, 170);
        imagefilledrectangle($img, 0, 0, $w, $h, $bg);

        $caption = mb_strimwidth(preg_replace('/\s+/', ' ', $r->prompt), 0, 90, '…');
        imagestring($img, 4, 24, (int) ($h / 2) - 8, $caption, $fg);
        imagerectangle($img, 8, 8, $w - 9, $h - 9, $fg);

        $tmp = tempnam(sys_get_temp_dir(), 'ph');
        imagepng($img, $tmp);
        imagedestroy($img);

        $disk = config('ai.storage_disk', 'local');
        $path = 'ai/image/placeholder_'.hash('sha256', $r->prompt.$r->aspectRatio).'.png';
        Storage::disk($disk)->put($path, file_get_contents($tmp));
        @unlink($tmp);

        return new MediaResult(
            ok: true, localPath: $path, mime: 'image/png', model: 'placeholder',
            raw: ['placeholder' => true, 'needs_replacement' => true],
        );
    }

    public function generateScene(MediaRequest $r): MediaResult
    {
        return $this->generateImage($r);
    }

    public function generateCharacter(MediaRequest $r): MediaResult
    {
        return $this->generateImage($r);
    }

    public function generateVideo(MediaRequest $r): MediaResult
    {
        return MediaResult::failure('Placeholder provider does not generate video.');
    }

    public function generateAudio(MediaRequest $r): MediaResult
    {
        return MediaResult::failure('Placeholder provider does not generate audio.');
    }
}
