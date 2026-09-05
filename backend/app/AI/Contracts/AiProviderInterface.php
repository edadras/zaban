<?php

namespace App\AI\Contracts;

/** Common surface every AI provider exposes to the orchestrator. */
interface AiProviderInterface
{
    /** Stable code matching ai_providers.code. */
    public function code(): string;

    /** Capabilities this provider can serve: text, image, video, audio, stt. */
    public function capabilities(): array;

    /** Whether the provider is configured well enough to be called. */
    public function isAvailable(): bool;
}
