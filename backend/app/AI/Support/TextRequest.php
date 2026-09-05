<?php

namespace App\AI\Support;

final class TextRequest
{
    public function __construct(
        public string $feature,
        public string $prompt,
        public ?string $system = null,
        public ?array $schema = null,
        public float $temperature = 0.7,
        public int $maxTokens = 1024,
        public ?int $userId = null,
        public ?string $model = null,
        public array $metadata = [],
        /** Identical inputs must not be paid for twice. */
        public bool $cacheable = true,
    ) {}

    public function cacheKey(): string
    {
        return hash('sha256', implode('|', [
            $this->feature, $this->system ?? '', $this->prompt,
            json_encode($this->schema), $this->temperature, $this->model ?? '',
        ]));
    }
}
