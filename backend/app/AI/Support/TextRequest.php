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
        /**
         * Images the model should look at alongside the prompt.
         *
         * Each entry is ['media_type' => 'image/png', 'data' => '<base64>'].
         * Reading a learner's handwritten page is a text task whose input
         * happens to be a picture, so it belongs here rather than in the media
         * pipeline, which exists to *generate* images.
         *
         * @var list<array{media_type:string,data:string}>
         */
        public array $images = [],
    ) {}

    public function cacheKey(): string
    {
        return hash('sha256', implode('|', [
            $this->feature, $this->system ?? '', $this->prompt,
            json_encode($this->schema), $this->temperature, $this->model ?? '',
            // Two requests with the same words but different pictures are
            // different requests.
            implode(',', array_map(fn ($i) => hash('sha256', $i['data'] ?? ''), $this->images)),
        ]));
    }
}
