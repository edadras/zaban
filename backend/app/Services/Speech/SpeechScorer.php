<?php

namespace App\Services\Speech;

/**
 * The measured 0-100 scores.
 *
 * Each score is a function of something that was actually observed, and any
 * component that could not be observed is returned as null with a reason rather
 * than as a plausible-looking number. The language model never touches these
 * values (spec 20).
 */
class SpeechScorer
{
    /** Relative weights of the overall score; renormalised over whatever was measurable. */
    public const WEIGHTS = [
        'pronunciation' => 0.30,
        'fluency' => 0.20,
        'completeness' => 0.20,
        'grammar' => 0.20,
        'vocabulary' => 0.10,
    ];

    /** Lexical diversity below/above which the vocabulary score bottoms out / tops out. */
    private const TTR_FLOOR = 0.15;
    private const TTR_CEILING = 0.55;

    /** Type-token ratio is unstable on short samples, so it is only reported above this length. */
    private const MIN_TOKENS_FOR_DIVERSITY = 20;

    public function __construct(private TextTokeniser $tokeniser) {}

    /** Share of the reference text the learner actually produced. */
    public function completeness(int $expectedWords, int $omittedWords): ?float
    {
        if ($expectedWords <= 0) {
            return null;
        }

        return round(max(0.0, ($expectedWords - $omittedWords) / $expectedWords) * 100, 2);
    }

    /**
     * Deviation from the reference sentence in the grammatical categories the
     * diff can name. Only meaningful against a reference text.
     *
     * @param  array<int,array{error_type:string}>  $findings
     */
    public function grammar(int $expectedWords, array $findings): ?float
    {
        if ($expectedWords <= 0) {
            return null;
        }

        $grammatical = array_filter(
            $findings,
            fn ($f) => in_array($f['error_type'], ['grammar', 'article', 'preposition', 'word_order'], true),
        );

        // Two points of penalty per error, expressed as a share of the sentence,
        // so one slip in a five-word sentence hurts more than one in twenty.
        $penalty = (count($grammatical) / $expectedWords) * 200;

        return round(max(0.0, 100 - $penalty), 2);
    }

    /**
     * Lexical diversity of what was said. Deliberately null for a read-aloud
     * task: repeating a given sentence says nothing about the learner's own
     * vocabulary range.
     *
     * @param  array<int,array{norm:string}>  $spokenTokens
     * @return array{score:?float, reason:?string, ttr:?float}
     */
    public function vocabulary(array $spokenTokens, bool $hasReferenceText): array
    {
        if ($hasReferenceText) {
            return [
                'score' => null,
                'reason' => 'Vocabulary is not scored on a read-aloud task: the words were supplied, not chosen.',
                'ttr' => null,
            ];
        }

        $tokens = array_values(array_filter(
            array_column($spokenTokens, 'norm'),
            fn ($t) => $t !== '' && ! $this->tokeniser->isFiller($t),
        ));

        if (count($tokens) < self::MIN_TOKENS_FOR_DIVERSITY) {
            return [
                'score' => null,
                'reason' => sprintf(
                    'Too short to measure vocabulary range: %d words, %d needed.',
                    count($tokens),
                    self::MIN_TOKENS_FOR_DIVERSITY,
                ),
                'ttr' => null,
            ];
        }

        $ttr = count(array_unique($tokens)) / count($tokens);
        $scaled = ($ttr - self::TTR_FLOOR) / (self::TTR_CEILING - self::TTR_FLOOR) * 100;

        return [
            'score' => round(max(0.0, min(100.0, $scaled)), 2),
            'reason' => null,
            'ttr' => round($ttr, 3),
        ];
    }

    /**
     * Weighted mean over the components that were measured. Missing components
     * drop out of both numerator and denominator instead of counting as zero.
     *
     * @param  array<string,?float>  $components
     */
    public function overall(array $components): ?float
    {
        $sum = 0.0;
        $weight = 0.0;
        foreach (self::WEIGHTS as $key => $w) {
            $value = $components[$key] ?? null;
            if ($value === null) {
                continue;
            }
            $sum += $value * $w;
            $weight += $w;
        }

        return $weight > 0 ? round($sum / $weight, 2) : null;
    }
}
