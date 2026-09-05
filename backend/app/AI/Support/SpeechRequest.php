<?php

namespace App\AI\Support;

final class SpeechRequest
{
    public function __construct(
        public string $audioPath,
        public string $language = 'en',
        public ?string $expectedText = null,
        public ?int $userId = null,
        public bool $wordTimestamps = true,
        public array $metadata = [],
    ) {}
}
