<?php

/**
 * AI provider wiring.
 *
 * Chains are ordered: the orchestrator walks a capability's chain and uses the
 * first provider that is configured and available. Putting a fallback last means
 * a vendor outage degrades a lesson rather than breaking it.
 */
return [
    'storage_disk' => env('AI_STORAGE_DISK', 'local'),

    'chains' => [
        'text' => array_values(array_filter(explode(',', env('AI_CHAIN_TEXT', 'anthropic')))),
        'image' => array_values(array_filter(explode(',', env('AI_CHAIN_IMAGE', 'higgsfield,placeholder')))),
        'video' => array_values(array_filter(explode(',', env('AI_CHAIN_VIDEO', 'higgsfield')))),
        'audio' => array_values(array_filter(explode(',', env('AI_CHAIN_AUDIO', 'higgsfield,espeak')))),
        'stt' => array_values(array_filter(explode(',', env('AI_CHAIN_STT', 'whisper')))),
    ],

    'providers' => [
        'anthropic' => [
            'driver' => App\AI\Providers\AnthropicTextProvider::class,
            'api_key' => env('ANTHROPIC_API_KEY'),
            'model' => env('ANTHROPIC_MODEL', 'claude-opus-5'),
            'max_tokens' => (int) env('ANTHROPIC_MAX_TOKENS', 8000),
            // Per-1M-token rates used for the cost ledger.
            'input_cost_per_mtok' => (float) env('ANTHROPIC_INPUT_COST', 5.00),
            'output_cost_per_mtok' => (float) env('ANTHROPIC_OUTPUT_COST', 25.00),
        ],

        'higgsfield' => [
            'driver' => App\AI\Providers\HiggsfieldProvider::class,
            'binary' => env('HIGGSFIELD_BINARY', 'higgsfield'),
            'timeout' => (int) env('HIGGSFIELD_TIMEOUT', 600),
            'credentials_path' => env('HIGGSFIELD_CREDENTIALS_PATH'),
            'models' => [
                'image' => env('HIGGSFIELD_IMAGE_MODEL', 'gpt_image_2'),
                'scene' => env('HIGGSFIELD_SCENE_MODEL', 'gpt_image_2'),
                'character' => env('HIGGSFIELD_CHARACTER_MODEL', 'nano_banana_pro'),
                'video' => env('HIGGSFIELD_VIDEO_MODEL', 'seedance_2_0'),
                'audio' => env('HIGGSFIELD_AUDIO_MODEL', 'seed_audio_1_0'),
            ],
        ],

        'whisper' => [
            'driver' => App\AI\Providers\WhisperSpeechProvider::class,
            'binary' => env('WHISPER_BINARY', 'whisper-cli'),
            'model_path' => env('WHISPER_MODEL_PATH'),
            'timeout' => (int) env('WHISPER_TIMEOUT', 300),
            // Montreal Forced Aligner or equivalent; without it, phoneme scoring
            // is unavailable rather than approximated from transcription.
            'aligner_binary' => env('ALIGNER_BINARY'),
            'aligner_dictionary' => env('ALIGNER_DICTIONARY'),
        ],

        'espeak' => [
            'driver' => App\AI\Providers\EspeakTtsProvider::class,
            'binary' => env('ESPEAK_BINARY', 'espeak-ng'),
        ],

        'placeholder' => [
            'driver' => App\AI\Providers\PlaceholderImageProvider::class,
        ],
    ],

    'limits' => [
        // Applied when no per-plan or per-user row exists in ai_usage_limits.
        'default_daily_requests' => (int) env('AI_DEFAULT_DAILY_REQUESTS', 200),
        'default_monthly_cost' => (float) env('AI_DEFAULT_MONTHLY_COST', 50.0),
    ],
];
