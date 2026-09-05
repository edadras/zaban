<?php

namespace App\AI\Providers;

use Anthropic\Client;
use App\AI\Contracts\TextProviderInterface;
use App\AI\Support\TextRequest;
use App\AI\Support\TextResult;

/**
 * Text generation through the official Anthropic PHP SDK.
 *
 * Used by the tutor, exercise generation, writing feedback and exam scoring.
 * Adaptive thinking is on because these are judgement tasks, not lookups.
 */
class AnthropicTextProvider implements TextProviderInterface
{
    public function __construct(
        private ?string $apiKey,
        private string $model,
        private int $maxTokens,
        private float $inputCostPerMTok,
        private float $outputCostPerMTok,
    ) {}

    public function code(): string
    {
        return 'anthropic';
    }

    public function capabilities(): array
    {
        return ['text'];
    }

    public function isAvailable(): bool
    {
        return (bool) $this->apiKey && class_exists(Client::class);
    }

    public function generateText(TextRequest $req): TextResult
    {
        $client = new Client(apiKey: $this->apiKey);

        $params = [
            'model' => $req->model ?: $this->model,
            'maxTokens' => min($req->maxTokens ?: $this->maxTokens, 32000),
            'messages' => [['role' => 'user', 'content' => $req->prompt]],
            // Judgement tasks (grading, feedback, item writing) benefit from
            // reasoning; the API decides how much.
            'thinking' => ['type' => 'adaptive'],
        ];
        if ($req->system) {
            $params['system'] = $req->system;
        }
        if ($req->schema) {
            $params['outputConfig'] = [
                'format' => [
                    'type' => 'json_schema',
                    'schema' => $req->schema + ['additionalProperties' => false],
                ],
            ];
        }

        $message = $client->messages->create(...$params);

        // Thinking blocks precede text; take the first text block.
        $text = null;
        foreach ($message->content as $block) {
            if (($block->type ?? null) === 'text') {
                $text = $block->text;
                break;
            }
        }

        if ($text === null) {
            return TextResult::failure('Anthropic response contained no text block.');
        }

        // A policy decline arrives as HTTP 200 with stop_reason "refusal".
        if (($message->stopReason ?? null) === 'refusal') {
            return TextResult::failure('Request was declined by the model safety system.');
        }

        $json = null;
        if ($req->schema) {
            $decoded = json_decode($text, true);
            if (! is_array($decoded)) {
                return TextResult::failure('Model did not return schema-valid JSON.');
            }
            $json = $decoded;
        }

        $in = (int) ($message->usage->inputTokens ?? 0);
        $out = (int) ($message->usage->outputTokens ?? 0);

        return new TextResult(
            ok: true,
            text: $text,
            json: $json,
            inputTokens: $in,
            outputTokens: $out,
            cost: ($in / 1_000_000 * $this->inputCostPerMTok) + ($out / 1_000_000 * $this->outputCostPerMTok),
            requestId: $message->id ?? null,
            model: $message->model ?? $this->model,
        );
    }
}
