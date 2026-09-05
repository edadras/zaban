<?php

namespace App\AI\Support;

final class TextResult
{
    public function __construct(
        public bool $ok,
        public ?string $text = null,
        public ?array $json = null,
        public ?string $error = null,
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        public float $cost = 0.0,
        public ?string $requestId = null,
        public ?string $model = null,
        public array $raw = [],
    ) {}

    public static function failure(string $error): self
    {
        return new self(ok: false, error: $error);
    }
}
