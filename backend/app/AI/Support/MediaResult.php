<?php

namespace App\AI\Support;

final class MediaResult
{
    public function __construct(
        public bool $ok,
        public ?string $url = null,
        public ?string $localPath = null,
        public ?string $mime = null,
        public ?string $error = null,
        public float $credits = 0.0,
        public float $cost = 0.0,
        public ?string $requestId = null,
        public ?string $model = null,
        public ?string $seed = null,
        public array $raw = [],
    ) {}

    public static function failure(string $error): self
    {
        return new self(ok: false, error: $error);
    }
}
