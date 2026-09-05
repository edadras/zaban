<?php

namespace App\AI\Support;

/**
 * A media generation brief.
 *
 * The teaching parameters are first-class rather than smuggled into the prompt
 * string, so the prompt builder can enforce the rules in spec section 19
 * (level-appropriate, culturally neutral, no text baked into images).
 */
final class MediaRequest
{
    public function __construct(
        public string $feature,
        public string $prompt,
        public ?string $negativePrompt = null,
        public string $aspectRatio = '16:9',
        public ?string $model = null,
        public ?int $seed = null,
        public ?int $userId = null,
        public ?string $referenceImageUrl = null,
        public ?int $durationSeconds = null,
        public ?string $voice = null,
        public array $metadata = [],
        public bool $cacheable = true,
    ) {}

    public function cacheKey(): string
    {
        return hash('sha256', implode('|', [
            $this->feature, $this->prompt, $this->negativePrompt ?? '',
            $this->aspectRatio, $this->model ?? '', (string) $this->seed,
            $this->referenceImageUrl ?? '', (string) $this->durationSeconds,
            $this->voice ?? '',
        ]));
    }
}
