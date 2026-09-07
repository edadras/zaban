<?php

namespace Tests\Feature\Ai\Support;

use App\AI\Contracts\TextProviderInterface;
use App\AI\Support\TextRequest;
use App\AI\Support\TextResult;

/**
 * A provider that answers with structured JSON, for the callers that ask for a
 * schema: writing marking, homework marking, and anything else that wants a
 * shape rather than a paragraph.
 */
class FakeJsonTextProvider implements TextProviderInterface
{
    public int $calls = 0;

    public function code(): string
    {
        return 'fake-json';
    }

    public function capabilities(): array
    {
        return ['text'];
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function generateText(TextRequest $request): TextResult
    {
        $this->calls++;

        $json = [
            'scores' => [
                'overall' => 72,
                'task_achievement' => 70,
                'coherence' => 75,
                'grammar' => 68,
                'vocabulary' => 74,
                'mechanics' => 80,
            ],
            'summary' => 'A clear paragraph with a few tense slips.',
            'strengths' => ['Good range of past-tense verbs.'],
            'next_steps' => ['Check subject-verb agreement.'],
            'corrections' => [],
        ];

        return new TextResult(
            ok: true,
            text: json_encode($json),
            json: $json,
            inputTokens: 200,
            outputTokens: 90,
            cost: 0.002,
            requestId: 'fake-json-'.$this->calls,
            model: 'fake-json-model',
        );
    }
}
