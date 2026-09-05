<?php

namespace App\Providers;

use App\AI\AiOrchestrator;
use App\AI\Providers\AnthropicTextProvider;
use App\AI\Providers\EspeakTtsProvider;
use App\AI\Providers\HiggsfieldProvider;
use App\AI\Providers\PlaceholderImageProvider;
use App\AI\Providers\WhisperSpeechProvider;
use App\AI\ProviderRegistry;
use Illuminate\Support\ServiceProvider;

class AiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ProviderRegistry::class);
        $this->app->singleton(AiOrchestrator::class);

        $this->app->bind(AnthropicTextProvider::class, fn () => new AnthropicTextProvider(
            apiKey: config('ai.providers.anthropic.api_key'),
            model: config('ai.providers.anthropic.model'),
            maxTokens: config('ai.providers.anthropic.max_tokens'),
            inputCostPerMTok: config('ai.providers.anthropic.input_cost_per_mtok'),
            outputCostPerMTok: config('ai.providers.anthropic.output_cost_per_mtok'),
        ));

        $this->app->bind(HiggsfieldProvider::class, fn () => new HiggsfieldProvider(
            binary: config('ai.providers.higgsfield.binary'),
            models: config('ai.providers.higgsfield.models', []),
            timeout: config('ai.providers.higgsfield.timeout'),
            credentialsPath: config('ai.providers.higgsfield.credentials_path'),
        ));

        $this->app->bind(WhisperSpeechProvider::class, fn () => new WhisperSpeechProvider(
            binary: config('ai.providers.whisper.binary'),
            modelPath: config('ai.providers.whisper.model_path'),
            timeout: config('ai.providers.whisper.timeout'),
            alignerBinary: config('ai.providers.whisper.aligner_binary'),
            alignerDictionary: config('ai.providers.whisper.aligner_dictionary'),
        ));

        $this->app->bind(EspeakTtsProvider::class, fn () => new EspeakTtsProvider(
            binary: config('ai.providers.espeak.binary'),
        ));

        $this->app->bind(PlaceholderImageProvider::class, fn () => new PlaceholderImageProvider);
    }
}
