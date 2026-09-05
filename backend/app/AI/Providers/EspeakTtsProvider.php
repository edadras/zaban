<?php

namespace App\AI\Providers;

use App\AI\Contracts\AiMediaProviderInterface;
use App\AI\Support\MediaRequest;
use App\AI\Support\MediaResult;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/**
 * Offline TTS fallback.
 *
 * Robotic compared with a hosted voice, but it keeps a pronunciation model
 * available when the primary provider is down or out of credit - a drill with a
 * plain voice still teaches; a drill with no audio does not.
 */
class EspeakTtsProvider implements AiMediaProviderInterface
{
    public function __construct(private string $binary) {}

    public function code(): string
    {
        return 'espeak';
    }

    public function capabilities(): array
    {
        return ['audio'];
    }

    public function isAvailable(): bool
    {
        $p = new Process(['which', $this->binary]);
        $p->run();

        return $p->isSuccessful();
    }

    public function generateAudio(MediaRequest $r): MediaResult
    {
        $disk = config('ai.storage_disk', 'local');
        $path = 'ai/audio/'.hash('sha256', $r->prompt.$r->voice).'.wav';
        if (Storage::disk($disk)->exists($path)) {
            return new MediaResult(ok: true, localPath: $path, mime: 'audio/wav', model: 'espeak-ng');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'tts').'.wav';
        $p = new Process([$this->binary, '-v', $r->voice ?: 'en-us', '-w', $tmp, $r->prompt]);
        $p->setTimeout(120);
        $p->run();

        if (! $p->isSuccessful() || ! is_file($tmp)) {
            return MediaResult::failure('espeak-ng synthesis failed.');
        }
        Storage::disk($disk)->put($path, file_get_contents($tmp));
        @unlink($tmp);

        return new MediaResult(ok: true, localPath: $path, mime: 'audio/wav', model: 'espeak-ng');
    }

    public function generateImage(MediaRequest $r): MediaResult
    {
        return MediaResult::failure('espeak does not generate images.');
    }

    public function generateVideo(MediaRequest $r): MediaResult
    {
        return MediaResult::failure('espeak does not generate video.');
    }

    public function generateCharacter(MediaRequest $r): MediaResult
    {
        return MediaResult::failure('espeak does not generate images.');
    }

    public function generateScene(MediaRequest $r): MediaResult
    {
        return MediaResult::failure('espeak does not generate images.');
    }
}
