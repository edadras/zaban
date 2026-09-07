<?php

namespace App\AI\Providers;

use App\AI\Contracts\TextProviderInterface;
use App\AI\Support\TextRequest;
use App\AI\Support\TextResult;
use Illuminate\Support\Facades\Http;

/**
 * Text generation through the OpenAI Chat Completions API.
 *
 * Used as the conversational / tutor text backend when Anthropic is not
 * configured. Talks over HTTPS with the platform HTTP client so we do not
 * need a separate Composer SDK.
 */
class OpenAiTextProvider implements TextProviderInterface
{
    public function __construct(
        private ?string $apiKey,
        private string $model,
        private int $maxTokens,
        private float $inputCostPerMTok,
        private float $outputCostPerMTok,
        private string $baseUrl = 'https://api.openai.com/v1',
    ) {}

    public function code(): string
    {
        return 'openai';
    }

    public function capabilities(): array
    {
        return ['text'];
    }

    public function isAvailable(): bool
    {
        return (bool) $this->apiKey;
    }

    public function generateText(TextRequest $req): TextResult
    {
        $messages = [];
        if ($req->system) {
            $messages[] = ['role' => 'system', 'content' => $req->system];
        }
        $messages[] = ['role' => 'user', 'content' => $this->content($req)];

        $payload = [
            'model' => $req->model ?: $this->model,
            'messages' => $messages,
            'temperature' => $req->temperature,
            'max_tokens' => min($req->maxTokens ?: $this->maxTokens, 16384),
        ];

        if ($req->schema) {
            $payload['response_format'] = [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'zaban_response',
                    'strict' => true,
                    'schema' => $req->schema + ['additionalProperties' => false],
                ],
            ];
        }

        try {
            $response = Http::withToken((string) $this->apiKey)
                ->acceptJson()
                ->timeout(120)
                ->post(rtrim($this->baseUrl, '/').'/chat/completions', $payload);
        } catch (\Throwable $e) {
            return TextResult::failure('OpenAI request failed: '.$e->getMessage());
        }

        if (! $response->successful()) {
            $message = $response->json('error.message')
                ?? ('HTTP '.$response->status());

            return TextResult::failure('OpenAI error: '.$message);
        }

        $body = $response->json();
        $text = $body['choices'][0]['message']['content'] ?? null;
        if (! is_string($text) || $text === '') {
            return TextResult::failure('OpenAI response contained no text.');
        }

        $json = null;
        if ($req->schema) {
            $decoded = json_decode($text, true);
            if (! is_array($decoded)) {
                return TextResult::failure('Model did not return schema-valid JSON.');
            }
            $json = $decoded;
        }

        $in = (int) ($body['usage']['prompt_tokens'] ?? 0);
        $out = (int) ($body['usage']['completion_tokens'] ?? 0);

        return new TextResult(
            ok: true,
            text: $text,
            json: $json,
            inputTokens: $in,
            outputTokens: $out,
            cost: ($in / 1_000_000 * $this->inputCostPerMTok) + ($out / 1_000_000 * $this->outputCostPerMTok),
            requestId: $body['id'] ?? null,
            model: $body['model'] ?? $this->model,
            raw: ['finish_reason' => $body['choices'][0]['finish_reason'] ?? null],
        );
    }

    /**
     * @return string|list<array<string,mixed>>
     */
    private function content(TextRequest $req): string|array
    {
        if ($req->images === []) {
            return $req->prompt;
        }

        $blocks = [];
        foreach ($req->images as $image) {
            $media = $image['media_type'] ?? 'image/png';
            $data = $image['data'] ?? '';
            $blocks[] = [
                'type' => 'image_url',
                'image_url' => [
                    'url' => 'data:'.$media.';base64,'.$data,
                ],
            ];
        }
        $blocks[] = ['type' => 'text', 'text' => $req->prompt];

        return $blocks;
    }
}
