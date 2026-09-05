<?php

namespace App\AI;

use App\AI\Contracts\AiMediaProviderInterface;
use App\AI\Contracts\AiProviderInterface;
use App\AI\Contracts\SpeechProviderInterface;
use App\AI\Contracts\TextProviderInterface;
use App\AI\Support\MediaRequest;
use App\AI\Support\MediaResult;
use App\AI\Support\SpeechRequest;
use App\AI\Support\SpeechResult;
use App\AI\Support\TextRequest;
use App\AI\Support\TextResult;
use App\Models\AiGeneration;
use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\AiRequest;
use App\Models\AiUsage;
use App\Models\AiUsageLimit;
use App\Models\MediaAsset;
use App\Models\Subscription;
use Illuminate\Support\Facades\Log;

/**
 * The single door to every AI vendor.
 *
 * Nothing else in the application may talk to a provider directly. Routing this
 * through one place is what makes the cost ledger, the reuse cache, the per-plan
 * limits and the fallback chain actually hold - each of which is worthless if
 * even one caller can bypass it.
 */
class AiOrchestrator
{
    public function __construct(private ProviderRegistry $registry) {}

    // ---------------------------------------------------------------- text

    public function text(TextRequest $req): TextResult
    {
        if ($cached = $this->cachedText($req)) {
            return $cached;
        }
        if ($deny = $this->limitCheck($req->userId, $req->feature)) {
            return TextResult::failure($deny);
        }

        foreach ($this->registry->chain('text') as $provider) {
            /** @var TextProviderInterface $provider */
            $log = $this->begin($provider, $req->feature, $req->userId, $req->cacheKey(), $req->model);
            try {
                $result = $provider->generateText($req);
            } catch (\Throwable $e) {
                $result = TextResult::failure($e->getMessage());
                Log::warning('ai.text.exception', ['provider' => $provider->code(), 'error' => $e->getMessage()]);
            }
            $this->finish($log, $result->ok, $result->error, [
                'input_tokens' => $result->inputTokens,
                'output_tokens' => $result->outputTokens,
                'estimated_cost' => $result->cost,
                'request_id' => $result->requestId,
            ]);

            if ($result->ok) {
                AiGeneration::create([
                    'ai_request_id' => $log->id,
                    'output_type' => $req->schema ? 'json' : 'text',
                    'prompt' => $req->prompt,
                    'output_text' => $result->text,
                    'output_json' => $result->json,
                    'parameters' => ['temperature' => $req->temperature, 'max_tokens' => $req->maxTokens],
                    'provider_metadata' => $result->raw,
                ]);
                $this->recordUsage($req->userId, $req->feature, $result->inputTokens, $result->outputTokens, 0.0, $result->cost);

                return $result;
            }
        }

        return TextResult::failure('No text provider could satisfy the request.');
    }

    // --------------------------------------------------------------- media

    public function image(MediaRequest $req): MediaResult
    {
        return $this->media($req, 'generateImage', 'image');
    }

    public function video(MediaRequest $req): MediaResult
    {
        return $this->media($req, 'generateVideo', 'video');
    }

    public function audio(MediaRequest $req): MediaResult
    {
        return $this->media($req, 'generateAudio', 'audio');
    }

    public function character(MediaRequest $req): MediaResult
    {
        return $this->media($req, 'generateCharacter', 'image');
    }

    public function scene(MediaRequest $req): MediaResult
    {
        return $this->media($req, 'generateScene', 'image');
    }

    private function media(MediaRequest $req, string $method, string $capability): MediaResult
    {
        // Standard lesson media is generated once and reused for every learner
        // (spec 18); only personalised briefs opt out via cacheable=false.
        if ($cached = $this->cachedMedia($req)) {
            return $cached;
        }
        if ($deny = $this->limitCheck($req->userId, $req->feature)) {
            return MediaResult::failure($deny);
        }

        foreach ($this->registry->chain($capability) as $provider) {
            if (! $provider instanceof AiMediaProviderInterface) {
                continue;
            }
            $log = $this->begin($provider, $req->feature, $req->userId, $req->cacheKey(), $req->model);
            try {
                /** @var MediaResult $result */
                $result = $provider->{$method}($req);
            } catch (\Throwable $e) {
                $result = MediaResult::failure($e->getMessage());
                Log::warning('ai.media.exception', ['provider' => $provider->code(), 'error' => $e->getMessage()]);
            }
            $this->finish($log, $result->ok, $result->error, [
                'credits_used' => $result->credits,
                'estimated_cost' => $result->cost,
                'request_id' => $result->requestId,
            ]);

            if ($result->ok) {
                $asset = $this->storeMedia($result, $capability);
                AiGeneration::create([
                    'ai_request_id' => $log->id,
                    'output_type' => $capability,
                    'prompt' => $req->prompt,
                    'negative_prompt' => $req->negativePrompt,
                    'media_asset_id' => $asset?->id,
                    'output_url' => $result->url,
                    'seed' => $result->seed,
                    'parameters' => [
                        'aspect_ratio' => $req->aspectRatio,
                        'duration_seconds' => $req->durationSeconds,
                        'voice' => $req->voice,
                    ],
                    'provider_metadata' => $result->raw,
                ]);
                $this->recordUsage($req->userId, $req->feature, 0, 0, $result->credits, $result->cost);

                return $result;
            }
        }

        return MediaResult::failure("No {$capability} provider could satisfy the request.");
    }

    // -------------------------------------------------------------- speech

    public function transcribe(SpeechRequest $req): SpeechResult
    {
        return $this->speech($req, 'transcribe');
    }

    public function align(SpeechRequest $req): SpeechResult
    {
        foreach ($this->registry->chain('stt') as $provider) {
            if ($provider instanceof SpeechProviderInterface && $provider->supportsAlignment()) {
                return $this->runSpeech($provider, $req, 'align');
            }
        }

        // No aligner configured: say so rather than passing off plain transcription
        // as phoneme-level scoring.
        return SpeechResult::failure('No forced-alignment provider is configured.');
    }

    private function speech(SpeechRequest $req, string $method): SpeechResult
    {
        foreach ($this->registry->chain('stt') as $provider) {
            if (! $provider instanceof SpeechProviderInterface) {
                continue;
            }
            $result = $this->runSpeech($provider, $req, $method);
            if ($result->ok) {
                return $result;
            }
        }

        return SpeechResult::failure('No speech provider could satisfy the request.');
    }

    private function runSpeech(SpeechProviderInterface $provider, SpeechRequest $req, string $method): SpeechResult
    {
        $log = $this->begin($provider, 'speech.'.$method, $req->userId, null, null);
        try {
            /** @var SpeechResult $result */
            $result = $provider->{$method}($req);
        } catch (\Throwable $e) {
            $result = SpeechResult::failure($e->getMessage());
        }
        $this->finish($log, $result->ok, $result->error, ['estimated_cost' => $result->cost]);
        if ($result->ok) {
            $this->recordUsage($req->userId, 'speech.'.$method, 0, 0, 0.0, $result->cost);
        }

        return $result;
    }

    // ------------------------------------------------------------ internals

    private function cachedText(TextRequest $req): ?TextResult
    {
        if (! $req->cacheable) {
            return null;
        }
        $hit = AiRequest::where('cache_key', $req->cacheKey())
            ->where('status', 'succeeded')->latest('id')->first();
        if (! $hit) {
            return null;
        }
        $gen = AiGeneration::where('ai_request_id', $hit->id)->first();
        if (! $gen) {
            return null;
        }
        $gen->increment('reuse_count');

        return new TextResult(ok: true, text: $gen->output_text, json: $gen->output_json,
            requestId: $hit->request_id, model: null, raw: ['cached' => true]);
    }

    private function cachedMedia(MediaRequest $req): ?MediaResult
    {
        if (! $req->cacheable) {
            return null;
        }
        $hit = AiRequest::where('cache_key', $req->cacheKey())
            ->where('status', 'succeeded')->latest('id')->first();
        if (! $hit) {
            return null;
        }
        $gen = AiGeneration::where('ai_request_id', $hit->id)->first();
        if (! $gen) {
            return null;
        }
        $gen->increment('reuse_count');
        $path = $gen->mediaAsset?->path;

        return new MediaResult(ok: true, url: $gen->output_url, localPath: $path,
            requestId: $hit->request_id, seed: $gen->seed, raw: ['cached' => true]);
    }

    /** Returns a denial reason, or null when the call is within limits. */
    private function limitCheck(?int $userId, string $feature): ?string
    {
        if (! $userId) {
            return null;
        }

        $planId = Subscription::where('user_id', $userId)
            ->whereIn('status', ['active', 'trialing'])
            ->value('plan_id');

        $limits = AiUsageLimit::query()
            ->where(fn ($q) => $q->whereNull('feature')->orWhere('feature', $feature))
            ->where(function ($q) use ($userId, $planId) {
                $q->where('user_id', $userId)
                    ->orWhere(fn ($x) => $x->whereNull('user_id')->whereNull('plan_id'));
                if ($planId) {
                    $q->orWhere('plan_id', $planId);
                }
            })
            ->get();

        foreach ($limits as $limit) {
            $since = $limit->period === 'day' ? now()->startOfDay() : now()->startOfMonth();
            $usage = AiUsage::where('user_id', $userId)
                ->when($limit->feature, fn ($q) => $q->where('feature', $limit->feature))
                ->where('usage_date', '>=', $since->toDateString());

            if ($limit->max_requests && (int) $usage->clone()->sum('request_count') >= $limit->max_requests) {
                return "AI request limit reached for this {$limit->period}.";
            }
            if ($limit->max_cost && (float) $usage->clone()->sum('estimated_cost') >= (float) $limit->max_cost) {
                return "AI spend limit reached for this {$limit->period}.";
            }
            if ($limit->max_credits && (float) $usage->clone()->sum('credits_used') >= (float) $limit->max_credits) {
                return "AI credit limit reached for this {$limit->period}.";
            }
        }

        return null;
    }

    private function begin(AiProviderInterface $provider, string $feature, ?int $userId, ?string $cacheKey, ?string $model): AiRequest
    {
        $row = AiProvider::firstOrCreate(
            ['code' => $provider->code()],
            ['name' => ucfirst($provider->code()), 'capabilities' => $provider->capabilities(),
             'driver' => $provider::class, 'is_active' => true],
        );
        $modelId = $model ? AiModel::where('ai_provider_id', $row->id)->where('code', $model)->value('id') : null;

        return AiRequest::create([
            'user_id' => $userId,
            'ai_provider_id' => $row->id,
            'ai_model_id' => $modelId,
            'feature' => $feature,
            'status' => 'running',
            'cache_key' => $cacheKey,
            'started_at' => now(),
        ]);
    }

    private function finish(AiRequest $log, bool $ok, ?string $error, array $fields): void
    {
        $log->update(array_filter([
            'status' => $ok ? 'succeeded' : 'failed',
            'error' => $error,
            'finished_at' => now(),
            'duration_ms' => $log->started_at ? (int) (now()->diffInMilliseconds($log->started_at)) : null,
        ] + $fields, fn ($v) => $v !== null));
    }

    private function recordUsage(?int $userId, string $feature, int $in, int $out, float $credits, float $cost): void
    {
        $row = AiUsage::firstOrCreate(
            ['user_id' => $userId, 'feature' => $feature, 'usage_date' => now()->toDateString()],
            ['request_count' => 0],
        );
        $row->increment('request_count');
        $row->increment('input_tokens', $in);
        $row->increment('output_tokens', $out);
        if ($credits) {
            $row->increment('credits_used', $credits);
        }
        if ($cost) {
            $row->increment('estimated_cost', $cost);
        }
    }

    private function storeMedia(MediaResult $result, string $type): ?MediaAsset
    {
        $path = $result->localPath ?: $result->url;
        if (! $path) {
            return null;
        }

        return MediaAsset::updateOrCreate(
            ['disk' => $result->localPath ? 'local' : 'remote', 'path' => $path],
            [
                'type' => $type,
                'mime' => $result->mime ?? match ($type) {
                    'image' => 'image/png', 'video' => 'video/mp4', 'audio' => 'audio/mpeg',
                    default => 'application/octet-stream',
                },
                'origin' => 'ai_generated',
                'copyright_status' => 'owned',
                'metadata' => ['seed' => $result->seed, 'model' => $result->model],
            ],
        );
    }
}
