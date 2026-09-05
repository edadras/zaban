<?php

namespace App\AI\Providers;

use App\AI\Contracts\AiMediaProviderInterface;
use App\AI\Support\MediaRequest;
use App\AI\Support\MediaResult;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Higgsfield media generation.
 *
 * Higgsfield ships a CLI (`higgsfield`) rather than a public REST API, so this
 * driver shells out to it and parses its JSON output. The binary must be
 * installed and authenticated on the host (`higgsfield auth login`); when it is
 * not, isAvailable() reports false and the orchestrator moves to the next
 * provider in the chain instead of failing the lesson.
 *
 * Credentials never leave the server: the CLI reads its own token file and the
 * client is never handed anything.
 */
class HiggsfieldProvider implements AiMediaProviderInterface
{
    public function __construct(
        private string $binary,
        private array $models,
        private int $timeout = 600,
        private ?string $credentialsPath = null,
    ) {}

    public function code(): string
    {
        return 'higgsfield';
    }

    public function capabilities(): array
    {
        return ['image', 'video', 'audio'];
    }

    public function isAvailable(): bool
    {
        if (! $this->binary) {
            return false;
        }
        $probe = new Process([$this->binary, 'auth', 'token'], env: $this->env());
        $probe->setTimeout(20);
        try {
            $probe->run();
        } catch (\Throwable) {
            return false;
        }

        return $probe->isSuccessful() && trim($probe->getOutput()) !== '';
    }

    public function generateImage(MediaRequest $r): MediaResult
    {
        return $this->run('image', $r, $r->model ?? $this->models['image'] ?? 'text2image_soul_v2');
    }

    public function generateVideo(MediaRequest $r): MediaResult
    {
        return $this->run('video', $r, $r->model ?? $this->models['video'] ?? 'seedance_2_0');
    }

    public function generateAudio(MediaRequest $r): MediaResult
    {
        return $this->run('audio', $r, $r->model ?? $this->models['audio'] ?? 'seed_audio_1_0');
    }

    public function generateCharacter(MediaRequest $r): MediaResult
    {
        return $this->run('image', $r, $r->model ?? $this->models['character'] ?? 'nano_banana_pro');
    }

    public function generateScene(MediaRequest $r): MediaResult
    {
        return $this->run('image', $r, $r->model ?? $this->models['scene'] ?? 'gpt_image_2');
    }

    private function run(string $kind, MediaRequest $r, string $model): MediaResult
    {
        $cmd = [$this->binary, 'generate', 'create', $model, '--prompt', $r->prompt, '--json'];

        if ($r->negativePrompt) {
            array_push($cmd, '--negative-prompt', $r->negativePrompt);
        }
        if ($r->aspectRatio) {
            array_push($cmd, '--aspect-ratio', $r->aspectRatio);
        }
        if ($r->seed !== null) {
            array_push($cmd, '--seed', (string) $r->seed);
        }
        if ($r->referenceImageUrl) {
            array_push($cmd, '--image', $r->referenceImageUrl);
        }
        if ($kind === 'video' && $r->durationSeconds) {
            array_push($cmd, '--duration', (string) $r->durationSeconds);
        }
        if ($kind === 'audio' && $r->voice) {
            array_push($cmd, '--voice', $r->voice);
        }

        $process = new Process($cmd, env: $this->env());
        $process->setTimeout($this->timeout);
        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            return MediaResult::failure('Higgsfield generation timed out.');
        }

        if (! $process->isSuccessful()) {
            return MediaResult::failure(trim($process->getErrorOutput()) ?: 'Higgsfield CLI returned a non-zero exit code.');
        }

        $payload = json_decode($process->getOutput(), true);
        if (! is_array($payload)) {
            return MediaResult::failure('Higgsfield CLI returned output that was not JSON.');
        }

        $url = $this->pluck($payload, ['url', 'output_url', 'result_url'])
            ?? data_get($payload, 'results.0.url');
        if (! $url) {
            return MediaResult::failure('Higgsfield response contained no output URL.');
        }

        return new MediaResult(
            ok: true,
            url: $url,
            localPath: $this->mirror($url, $kind),
            mime: $this->mimeFor($kind),
            credits: (float) ($this->pluck($payload, ['credits', 'credits_used']) ?? 0),
            cost: 0.0,
            requestId: $this->pluck($payload, ['id', 'job_id', 'request_id']),
            model: $model,
            seed: $this->pluck($payload, ['seed']) !== null ? (string) $this->pluck($payload, ['seed']) : null,
            raw: $payload,
        );
    }

    /**
     * Copy the provider's output into our own storage. Vendor URLs expire, and a
     * lesson that renders today must still render next year.
     */
    private function mirror(string $url, string $kind): ?string
    {
        $ext = match ($kind) { 'video' => 'mp4', 'audio' => 'mp3', default => 'png' };
        $path = 'ai/'.$kind.'/'.hash('sha256', $url).'.'.$ext;
        if (Storage::disk(config('ai.storage_disk', 'local'))->exists($path)) {
            return $path;
        }
        $bytes = @file_get_contents($url);
        if ($bytes === false) {
            return null;
        }
        Storage::disk(config('ai.storage_disk', 'local'))->put($path, $bytes);

        return $path;
    }

    private function mimeFor(string $kind): string
    {
        return match ($kind) {
            'video' => 'video/mp4',
            'audio' => 'audio/mpeg',
            default => 'image/png',
        };
    }

    private function pluck(array $payload, array $keys): mixed
    {
        foreach ($keys as $k) {
            if (array_key_exists($k, $payload) && $payload[$k] !== null) {
                return $payload[$k];
            }
        }

        return null;
    }

    private function env(): array
    {
        return array_filter([
            'HIGGSFIELD_CREDENTIALS_PATH' => $this->credentialsPath,
            'HIGGSFIELD_NO_UPDATE_CHECK' => '1',
            'HOME' => getenv('HOME') ?: '/root',
        ]);
    }
}
