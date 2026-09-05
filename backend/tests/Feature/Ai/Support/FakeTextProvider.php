<?php

namespace Tests\Feature\Ai\Support;

use App\AI\Contracts\TextProviderInterface;
use App\AI\Support\TextRequest;
use App\AI\Support\TextResult;

/** A provider that succeeds, so the orchestrator's own write path is exercised. */
class FakeTextProvider implements TextProviderInterface
{
    public int $calls = 0;

    public function __construct(private bool $available = true, private ?string $failWith = null) {}

    public function code(): string
    {
        return 'fake-text';
    }

    public function capabilities(): array
    {
        return ['text'];
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function generateText(TextRequest $request): TextResult
    {
        $this->calls++;

        if ($this->failWith) {
            return TextResult::failure($this->failWith);
        }

        return new TextResult(
            ok: true,
            text: 'generated: '.$request->prompt,
            inputTokens: 120,
            outputTokens: 45,
            cost: 0.0012,
            requestId: 'fake-'.$this->calls,
            model: 'fake-model-1',
        );
    }
}
