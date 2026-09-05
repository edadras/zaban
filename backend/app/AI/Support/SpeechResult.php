<?php

namespace App\AI\Support;

final class SpeechResult
{
    /**
     * @param  array<int,array{word:string,start_ms:int,end_ms:int,confidence:float}>  $words
     * @param  array<int,array{word_index:int,phoneme:string,start_ms:int,end_ms:int,score:float}>  $phonemes
     */
    public function __construct(
        public bool $ok,
        public ?string $transcript = null,
        public array $words = [],
        public array $phonemes = [],
        public ?string $error = null,
        public float $cost = 0.0,
        public ?string $model = null,
        public ?int $durationMs = null,
        public array $raw = [],
    ) {}

    public static function failure(string $error): self
    {
        return new self(ok: false, error: $error);
    }
}
