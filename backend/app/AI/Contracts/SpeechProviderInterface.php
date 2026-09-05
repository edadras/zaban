<?php

namespace App\AI\Contracts;

use App\AI\Support\SpeechRequest;
use App\AI\Support\SpeechResult;

interface SpeechProviderInterface extends AiProviderInterface
{
    /** Transcribe with word timings where the provider supports them. */
    public function transcribe(SpeechRequest $request): SpeechResult;

    /**
     * Align expected text against the audio and score each phoneme.
     * Providers without forced alignment should report supportsAlignment() false
     * so the orchestrator can fall back rather than silently degrade.
     */
    public function align(SpeechRequest $request): SpeechResult;

    public function supportsAlignment(): bool;
}
