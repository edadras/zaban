<?php

namespace App\Services\Exam;

use App\Models\Exercise;
use App\Models\ExerciseAnswer;
use App\Models\ExerciseOption;
use Illuminate\Support\Collection;

/**
 * Deterministic marking against the stored answer key.
 *
 * Listening and reading sections must produce the same score for the same
 * answers every time and without an AI call, so nothing here consults a model:
 * an item is marked from exercise_options.is_correct or from exercise_answers,
 * using the match_mode the author chose.
 *
 * @phpstan-type Mark array{is_correct: bool, score: float, credit: float, expected: ?string, given: ?string, detail: array}
 */
class ObjectiveGrader
{
    /** A near-miss has to be this close before spelling tolerance applies. */
    private const FUZZY_THRESHOLD = 0.85;

    /**
     * Mark one item. $response is whatever the client submitted for it.
     *
     * @return array{is_correct: bool, score: float, expected: ?string, given: ?string, detail: array}
     */
    public function grade(Exercise $exercise, mixed $response): array
    {
        $options = $exercise->relationLoaded('options') ? $exercise->options : $exercise->options()->get();
        $answers = $exercise->relationLoaded('answers') ? $exercise->answers : $exercise->answers()->get();

        $correctOptions = $options->where('is_correct', true);

        if ($correctOptions->isNotEmpty()) {
            return $this->gradeChoice($options, $correctOptions, $response);
        }

        if ($answers->isNotEmpty()) {
            return $this->gradeText($answers, $response);
        }

        // No key at all: refuse to guess. The caller records this as unmarkable
        // rather than as a wrong answer.
        return [
            'is_correct' => false,
            'score' => 0.0,
            'expected' => null,
            'given' => $this->stringify($response),
            'detail' => ['unmarkable' => true, 'reason' => 'no_answer_key'],
        ];
    }

    /**
     * Selection items. Partial credit is proportional to the correct options
     * chosen, minus wrong ones, which is how multi-answer items are really marked.
     *
     * @param  Collection<int, ExerciseOption>  $options
     * @param  Collection<int, ExerciseOption>  $correct
     */
    private function gradeChoice(Collection $options, Collection $correct, mixed $response): array
    {
        $selected = $this->selectedOptionIds($options, $response);
        $correctIds = $correct->pluck('id')->map(fn ($id) => (int) $id)->all();

        $hits = count(array_intersect($selected, $correctIds));
        $misses = count(array_diff($selected, $correctIds));
        $needed = max(1, count($correctIds));

        $score = max(0.0, ($hits - $misses) / $needed);
        $isCorrect = $hits === $needed && $misses === 0;

        return [
            'is_correct' => $isCorrect,
            'score' => round($isCorrect ? 1.0 : min(1.0, $score), 4),
            'expected' => $correct->pluck('text')->filter()->implode(' | ') ?: null,
            'given' => $options->whereIn('id', $selected)->pluck('text')->filter()->implode(' | ') ?: $this->stringify($response),
            'detail' => [
                'kind' => 'choice',
                'selected_option_ids' => $selected,
                'correct_option_ids' => $correctIds,
                'distractor_rationale' => $options->whereIn('id', array_diff($selected, $correctIds))
                    ->pluck('distractor_rationale', 'id')->filter()->all(),
            ],
        ];
    }

    /**
     * Text items, including multi-blank ones. Each blank is marked against every
     * accepted variant and takes the best credit available.
     *
     * @param  Collection<int, ExerciseAnswer>  $answers
     */
    private function gradeText(Collection $answers, mixed $response): array
    {
        $given = $this->givenByBlank($response);
        $byBlank = $answers->groupBy(fn (ExerciseAnswer $a) => (int) $a->blank_index);

        $perBlank = [];
        $total = 0.0;

        foreach ($byBlank as $index => $variants) {
            $input = $given[$index] ?? null;
            $best = 0.0;
            $expected = $variants->firstWhere('is_primary', true)?->value ?? $variants->first()->value;

            foreach ($variants as $variant) {
                if ($input === null) {
                    continue;
                }
                if ($this->matches($input, $variant)) {
                    $best = max($best, (float) $variant->credit);
                }
            }

            $total += $best;
            $perBlank[] = [
                'blank_index' => (int) $index,
                'given' => $input,
                'expected' => $expected,
                'credit' => round($best, 4),
            ];
        }

        $blanks = max(1, $byBlank->count());
        $score = round($total / $blanks, 4);

        return [
            'is_correct' => $score >= 1.0,
            'score' => min(1.0, $score),
            'expected' => collect($perBlank)->pluck('expected')->implode(' | '),
            'given' => collect($perBlank)->pluck('given')->implode(' | '),
            'detail' => ['kind' => 'text', 'blanks' => $perBlank],
        ];
    }

    private function matches(string $input, ExerciseAnswer $answer): bool
    {
        $expected = (string) $answer->value;

        return match ($answer->match_mode) {
            'exact' => $input === $expected,
            'regex' => $this->regexMatches($expected, $input),
            'fuzzy' => $this->similarity($this->normalise($input), $this->normalise($expected)) >= self::FUZZY_THRESHOLD,
            // 'semantic' needs a model, which objective sections must not use;
            // it degrades to normalised matching rather than silently calling AI.
            default => $this->normalise($input) === $this->normalise($expected),
        };
    }

    private function regexMatches(string $pattern, string $input): bool
    {
        $delimited = '/^'.str_replace('/', '\/', $pattern).'$/iu';
        $result = @preg_match($delimited, $input);

        return $result === 1;
    }

    /** @return int[] */
    private function selectedOptionIds(Collection $options, mixed $response): array
    {
        $raw = $response;
        if (is_array($response)) {
            $raw = $response['option_ids'] ?? $response['selected'] ?? $response['option_id'] ?? $response['value'] ?? $response;
        }

        $values = is_array($raw) ? array_values($raw) : [$raw];
        $ids = [];

        foreach ($values as $value) {
            if ($value === null || $value === '') {
                continue;
            }
            // Accept an option id, a 0-based index, or the option text, because
            // three different clients have all been seen sending each of these.
            if (is_numeric($value) && $options->contains('id', (int) $value)) {
                $ids[] = (int) $value;

                continue;
            }
            if (is_numeric($value)) {
                $byPosition = $options->firstWhere('position', (int) $value);
                if ($byPosition) {
                    $ids[] = (int) $byPosition->id;

                    continue;
                }
            }
            if (is_string($value)) {
                $byText = $options->first(fn (ExerciseOption $o) => $this->normalise((string) $o->text) === $this->normalise($value));
                if ($byText) {
                    $ids[] = (int) $byText->id;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /** @return array<int, string> blank index => submitted text */
    private function givenByBlank(mixed $response): array
    {
        if (is_string($response) || is_numeric($response)) {
            return [0 => (string) $response];
        }
        if (! is_array($response)) {
            return [];
        }

        $raw = $response['answers'] ?? $response['blanks'] ?? $response['order'] ?? $response['text'] ?? $response['value'] ?? $response;

        if (is_string($raw) || is_numeric($raw)) {
            return [0 => (string) $raw];
        }
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        $position = 0;
        foreach ($raw as $key => $value) {
            if (is_array($value)) {
                continue;
            }
            $index = is_int($key) || ctype_digit((string) $key) ? (int) $key : $position;
            $out[$index] = (string) $value;
            $position++;
        }

        return $out;
    }

    private function normalise(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/[^\p{L}\p{N}\'\- ]+/u', ' ', $value) ?? $value;

        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    private function similarity(string $a, string $b): float
    {
        if ($a === $b) {
            return 1.0;
        }
        $length = max(mb_strlen($a), mb_strlen($b));
        if ($length === 0) {
            return 0.0;
        }
        // levenshtein() is byte-based; on multibyte input that only makes the
        // tolerance stricter, never looser, so it is safe as a near-miss check.
        return 1 - (levenshtein($a, $b) / $length);
    }

    private function stringify(mixed $response): ?string
    {
        if ($response === null) {
            return null;
        }
        if (is_scalar($response)) {
            return (string) $response;
        }

        return json_encode($response, JSON_UNESCAPED_UNICODE) ?: null;
    }
}
