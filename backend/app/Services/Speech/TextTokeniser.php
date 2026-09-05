<?php

namespace App\Services\Speech;

/**
 * Turns text and speech-to-text output into the comparable token stream the
 * aligner works on.
 *
 * The normalised form is what gets compared; the raw form is what gets shown
 * back to the learner, so both are carried side by side.
 */
class TextTokeniser
{
    /** Hesitation sounds. Deliberately excludes "like" / "you know", which are real words. */
    public const FILLERS = ['um', 'uh', 'erm', 'er', 'ah', 'eh', 'mm', 'hmm', 'mmm', 'uhm', 'uhh', 'umm'];

    /**
     * @return array<int,array{raw:string,norm:string}>
     */
    public function tokenise(?string $text): array
    {
        if ($text === null || trim($text) === '') {
            return [];
        }

        $parts = preg_split('/\s+/u', trim($text)) ?: [];
        $out = [];
        foreach ($parts as $part) {
            $norm = $this->normalise($part);
            if ($norm === '') {
                continue;
            }
            $out[] = ['raw' => $part, 'norm' => $norm];
        }

        return $out;
    }

    /**
     * Speech-provider word rows, normalised into the same shape and carrying the
     * timings the fluency measurements need.
     *
     * @param  array<int,array{word:string,start_ms?:int,end_ms?:int,confidence?:float}>  $words
     * @return array<int,array{raw:string,norm:string,start_ms:?int,end_ms:?int,confidence:?float}>
     */
    public function fromProviderWords(array $words): array
    {
        $out = [];
        foreach ($words as $w) {
            $raw = trim((string) ($w['word'] ?? ''));
            $norm = $this->normalise($raw);
            if ($norm === '') {
                continue;
            }
            $out[] = [
                'raw' => $raw,
                'norm' => $norm,
                'start_ms' => isset($w['start_ms']) ? (int) $w['start_ms'] : null,
                'end_ms' => isset($w['end_ms']) ? (int) $w['end_ms'] : null,
                'confidence' => isset($w['confidence']) ? (float) $w['confidence'] : null,
            ];
        }

        return $out;
    }

    /** Lower-cased, punctuation-stripped, apostrophes kept because they are contrastive ("were" vs "we're"). */
    public function normalise(string $word): string
    {
        $word = mb_strtolower(trim($word));
        $word = str_replace(['’', '‘', '`'], "'", $word);
        $word = preg_replace('/[^\p{L}\p{N}\'-]/u', '', $word) ?? '';

        return trim($word, "'-");
    }

    /** @param array<int,array{norm:string}> $tokens */
    public function countFillers(array $tokens): int
    {
        $n = 0;
        foreach ($tokens as $t) {
            if (in_array($t['norm'], self::FILLERS, true)) {
                $n++;
            }
        }

        return $n;
    }

    public function isFiller(string $norm): bool
    {
        return in_array($norm, self::FILLERS, true);
    }
}
