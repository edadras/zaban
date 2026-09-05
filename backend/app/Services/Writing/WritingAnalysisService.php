<?php

namespace App\Services\Writing;

use App\AI\AiOrchestrator;
use App\AI\Support\TextRequest;
use App\Models\CefrLevel;
use App\Models\WritingAttempt;
use Illuminate\Support\Facades\Log;

/**
 * Marks a piece of learner writing.
 *
 * The rubric is deliberately the four dimensions the exam examiner already
 * uses, plus mechanics. Practice feedback and exam feedback should speak one
 * language to the learner rather than two private vocabularies, so a learner
 * who improves their "coherence" score in practice knows what that will mean
 * in the exam.
 *
 * Corrections come back as spans rather than a rewritten text on purpose. A
 * model handed an essay will happily return a better essay, and the learner
 * learns nothing from being shown prose they did not write. Spans can be shown
 * in place, against what they actually wrote.
 */
class WritingAnalysisService
{
    private const FEATURE = 'writing.analysis';

    public function __construct(private AiOrchestrator $ai) {}

    public function analyse(WritingAttempt $attempt): bool
    {
        $text = trim((string) $attempt->text);

        if ($text === '') {
            return $this->failed($attempt, 'There is no text to mark.');
        }

        if (! $attempt->textIsAuthoritative()) {
            return $this->failed(
                $attempt,
                'The recognised text has not been confirmed by the learner yet.',
            );
        }

        $attempt->update(['status' => WritingAttempt::STATUS_SCORING]);

        $prompt = $attempt->prompt;
        $level = $attempt->cefr_level_id ? CefrLevel::find($attempt->cefr_level_id) : null;

        $result = $this->ai->text(new TextRequest(
            feature: self::FEATURE,
            prompt: $this->userPrompt($text, $prompt, $level?->code),
            system: $this->systemPrompt($level?->code),
            schema: $this->schema(),
            // Marking must be repeatable: the same paragraph should not score
            // 68 one day and 79 the next.
            temperature: 0.15,
            maxTokens: 2000,
            userId: (int) $attempt->user_id,
            metadata: [
                'writing_attempt_id' => $attempt->id,
                'source' => $attempt->source,
                'word_count' => $attempt->word_count,
            ],
            // One learner's own work: never served from, nor added to, a
            // shared cache.
            cacheable: false,
        ));

        if (! $result->ok || ! is_array($result->json)) {
            Log::warning('writing.analysis.failed', [
                'writing_attempt_id' => $attempt->id,
                'error' => $result->error,
            ]);

            return $this->failed($attempt, $result->error ?: 'The marker did not return a usable result.');
        }

        return $this->store($attempt, $result->json, $result->model);
    }

    private function store(WritingAttempt $attempt, array $json, ?string $model): bool
    {
        $scores = $this->normaliseScores($json['scores'] ?? []);

        $attempt->update([
            'status' => WritingAttempt::STATUS_SCORED,
            'overall_score' => $scores['overall'],
            'task_achievement_score' => $scores['task_achievement'],
            'coherence_score' => $scores['coherence'],
            'grammar_score' => $scores['grammar'],
            'vocabulary_score' => $scores['vocabulary'],
            'mechanics_score' => $scores['mechanics'],
            'corrections' => $this->normaliseCorrections($json['corrections'] ?? [], (string) $attempt->text),
            'feedback' => [
                'summary' => $json['summary'] ?? null,
                'strengths' => array_values(array_filter((array) ($json['strengths'] ?? []), 'is_string')),
                'next_steps' => array_values(array_filter((array) ($json['next_steps'] ?? []), 'is_string')),
            ],
            'analyser' => $model,
            'scored_at' => now(),
            'error' => null,
        ]);

        return true;
    }

    /**
     * @return array<string,float|null>
     */
    private function normaliseScores(array $raw): array
    {
        $out = [];

        foreach (['overall', 'task_achievement', 'coherence', 'grammar', 'vocabulary', 'mechanics'] as $key) {
            $value = $raw[$key] ?? null;
            $out[$key] = is_numeric($value) ? round(max(0, min(100, (float) $value)), 2) : null;
        }

        // A missing overall is derivable; a missing dimension is not, and
        // inventing one would be a fabricated mark.
        if ($out['overall'] === null) {
            $parts = array_filter([
                $out['task_achievement'], $out['coherence'],
                $out['grammar'], $out['vocabulary'], $out['mechanics'],
            ], fn ($v) => $v !== null);

            $out['overall'] = $parts ? round(array_sum($parts) / count($parts), 2) : null;
        }

        return $out;
    }

    /**
     * Keep only corrections that actually point at something in the text.
     *
     * A span the learner cannot find in their own writing is worse than no
     * correction: it reads as the app inventing mistakes.
     *
     * @return list<array<string,mixed>>
     */
    private function normaliseCorrections(array $raw, string $text): array
    {
        $out = [];

        foreach ($raw as $item) {
            if (! is_array($item)) {
                continue;
            }

            $original = trim((string) ($item['original'] ?? ''));

            if ($original === '' || ! str_contains($text, $original)) {
                continue;
            }

            $out[] = [
                'original' => $original,
                'suggestion' => trim((string) ($item['suggestion'] ?? '')),
                'kind' => (string) ($item['kind'] ?? 'other'),
                'explanation' => trim((string) ($item['explanation'] ?? '')),
            ];
        }

        return $out;
    }

    private function systemPrompt(?string $cefr): string
    {
        $level = $cefr ? "The learner is working at CEFR level {$cefr}." : 'The learner\'s level is unknown.';

        return implode(' ', [
            'You mark writing by learners of English.',
            $level,
            'Mark what is in front of you against what a learner at that level should manage,',
            'not against a native writer.',
            'Every correction must quote the learner\'s own words exactly as they wrote them,',
            'so it can be shown in place. Never rewrite the whole text.',
            'Be specific and kind. Name what works before what does not.',
            'If the writing is too short or off-topic to judge, say so in the summary and score it low',
            'rather than inventing merit.',
        ]);
    }

    private function userPrompt(string $text, $prompt, ?string $cefr): string
    {
        $task = $prompt
            ? "The task set was: \"{$prompt->prompt}\"".
              ($prompt->min_words ? " Expected length: {$prompt->min_words}-{$prompt->max_words} words." : '')
            : 'No specific task was set; mark it as free writing.';

        return "{$task}\n\nThe learner wrote:\n\n{$text}";
    }

    /**
     * @return array<string,mixed>
     */
    private function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'scores' => [
                    'type' => 'object',
                    'properties' => [
                        'overall' => ['type' => 'number'],
                        'task_achievement' => ['type' => 'number'],
                        'coherence' => ['type' => 'number'],
                        'grammar' => ['type' => 'number'],
                        'vocabulary' => ['type' => 'number'],
                        'mechanics' => ['type' => 'number'],
                    ],
                    'required' => ['task_achievement', 'coherence', 'grammar', 'vocabulary', 'mechanics'],
                ],
                'summary' => ['type' => 'string'],
                'strengths' => ['type' => 'array', 'items' => ['type' => 'string']],
                'next_steps' => ['type' => 'array', 'items' => ['type' => 'string']],
                'corrections' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'original' => ['type' => 'string'],
                            'suggestion' => ['type' => 'string'],
                            'kind' => ['type' => 'string', 'enum' => ['grammar', 'vocabulary', 'spelling', 'punctuation', 'register', 'other']],
                            'explanation' => ['type' => 'string'],
                        ],
                        'required' => ['original', 'suggestion', 'kind'],
                    ],
                ],
            ],
            'required' => ['scores', 'summary'],
        ];
    }

    private function failed(WritingAttempt $attempt, string $reason): bool
    {
        $attempt->update([
            'status' => WritingAttempt::STATUS_FAILED,
            'error' => $reason,
        ]);

        return false;
    }
}
