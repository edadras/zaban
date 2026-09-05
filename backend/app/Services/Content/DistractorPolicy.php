<?php

namespace App\Services\Content;

use Illuminate\Support\Str;

/**
 * Chooses wrong answers that are actually wrong.
 *
 * The first generator drew distractors from the other words in the same lesson,
 * on the reasoning that same-topic words are confusable. They are - and that is
 * the defect. A lesson teaching greetings taught "fine" and "very well" side by
 * side, so "I'm ______, thanks." shipped with two correct answers and marked the
 * learner wrong for choosing the other one.
 *
 * A distractor is only safe when we can show it does not fit. Two grades of
 * evidence, and the item is tiered by which one it rests on:
 *
 *   PROVEN   both words carry a definition and the definitions do not overlap,
 *            so they mean different things. Sound enough to measure with.
 *   PLAUSIBLE the distractor is taught in a different thematic module and shares
 *            no word stem with the target. Very unlikely to fit, but unproven -
 *            good practice material, not placement material.
 *
 * Anything weaker builds no item at all.
 */
class DistractorPolicy
{
    public const PROVEN = 'proven';
    public const PLAUSIBLE = 'plausible';

    /** Definitions sharing more than this share of their content words describe the same idea. */
    private const OVERLAP_LIMIT = 0.34;

    /** Two content words sharing a stem this long are morphological variants. */
    private const STEM_LENGTH = 4;

    private const STOPWORDS = [
        'a', 'an', 'the', 'and', 'or', 'but', 'of', 'to', 'in', 'on', 'at', 'for',
        'with', 'is', 'are', 'was', 'were', 'be', 'been', 'being', 'have', 'has',
        'had', 'do', 'does', 'did', 'it', 'its', 'you', 'your', 'he', 'she', 'they',
        'we', 'i', 'this', 'that', 'these', 'those', 'not', 'no', 'so', 'as', 'by',
        'from', 'if', 'then', 'than', 'there', 'here', 'very', 'someone', 'somebody',
        'something', 'people', 'person', 'who', 'which', 'when', 'what', 'about',
        'used', 'usually', 'often', 'more', 'most', 'other', 'way', 'make', 'makes',
    ];

    /**
     * @param  array<int, array{term: string, definition: ?string, module_id: ?int}>  $candidates
     * @return array{options: array<int, string>, grade: string}|null
     */
    public function choose(
        string $term,
        ?string $definition,
        string $stem,
        array $candidates,
        int $count = 3,
        ?int $targetModuleId = null,
    ): ?array {
        $proven = [];
        $plausible = [];

        foreach ($candidates as $c) {
            $candidate = trim((string) ($c['term'] ?? ''));
            if ($candidate === '' || $this->sameWord($term, $candidate)) {
                continue;
            }

            if (! $this->isUsableTerm($candidate)) {
                continue;
            }

            // A word already printed in the stem cannot be the missing one.
            if ($this->appearsIn($stem, $candidate)) {
                continue;
            }

            if ($this->overlapsTerm($term, $candidate)) {
                continue;
            }

            if ($this->provablyDistinct($definition, $c['definition'] ?? null)) {
                $proven[] = $candidate;
            } elseif ($targetModuleId !== null
                && ($c['module_id'] ?? null) !== null
                && $c['module_id'] !== $targetModuleId) {
                $plausible[] = $candidate;
            }
        }

        $proven = array_values(array_unique($proven));
        $plausible = array_values(array_unique(array_diff($plausible, $proven)));

        if (count($proven) >= $count) {
            return ['options' => array_slice($proven, 0, $count), 'grade' => self::PROVEN];
        }

        $mixed = array_merge($proven, $plausible);
        if (count($mixed) >= $count) {
            return ['options' => array_slice($mixed, 0, $count), 'grade' => self::PLAUSIBLE];
        }

        return null;
    }

    /**
     * Does this read as a word the book teaches?
     *
     * Extraction debris reaches the concept list too, and a wrong answer like
     * "nosh grub sarnie" or "I've got ..." gives the item away without the
     * learner knowing anything.
     */
    public function isUsableTerm(string $term): bool
    {
        $t = trim($term);

        if (mb_strlen($t) < 2 || mb_strlen($t) > 40) {
            return false;
        }

        if (preg_match('/[*=\[\]{}<>|\x{2026}]|\.{2,}/u', $t)) {
            return false;
        }

        if (! preg_match('/^[\p{L}]/u', $t)) {
            return false;
        }

        // A sentence, not a headword. The books open with units about how to
        // study, and their section headings - "What does knowing a new word
        // mean?" - were reaching the catalogue as vocabulary.
        if (preg_match('/[?!]|\.\s|[.;:]$/u', $t)) {
            return false;
        }

        // A list, not a headword: "a swim, a coffee" is two things the book
        // taught side by side, caught by one bold run.
        if (str_contains($t, ',') || str_contains($t, ';')) {
            return false;
        }

        return str_word_count($t) <= 4;
    }

    /**
     * Do these two definitions describe different things?
     *
     * Unprovable when either word is undefined, and unprovable is treated as
     * unsafe - that is the whole point of the tiering.
     */
    public function provablyDistinct(?string $a, ?string $b): bool
    {
        $left = $this->contentWords($a);
        $right = $this->contentWords($b);

        if ($left === [] || $right === []) {
            return false;
        }

        $shared = array_intersect($left, $right);
        $union = array_unique(array_merge($left, $right));

        if ($union === []) {
            return false;
        }

        if (count($shared) / count($union) > self::OVERLAP_LIMIT) {
            return false;
        }

        // Different words, same root: "yearning for" against "longing for" share
        // no token but "yearn"/"long" both gloss as wanting something.
        foreach ($left as $l) {
            foreach ($right as $r) {
                if ($this->shareStem($l, $r)) {
                    return false;
                }
            }
        }

        return true;
    }

    /** One term contained in, or morphologically the same as, the other. */
    public function overlapsTerm(string $a, string $b): bool
    {
        $left = $this->contentWords($a);
        $right = $this->contentWords($b);

        if ($left === [] || $right === []) {
            // Both are pure function words ("in", "on"); we cannot tell them apart.
            return true;
        }

        if (array_intersect($left, $right) !== []) {
            return true;
        }

        foreach ($left as $l) {
            foreach ($right as $r) {
                if ($this->shareStem($l, $r)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function appearsIn(string $haystack, string $needle): bool
    {
        $pattern = '/(?<![\w\x{2019}\'-])'
            .implode('\s+', array_map(
                fn ($p) => preg_quote($p, '/'),
                preg_split('/\s+/u', trim($needle)) ?: [],
            ))
            .'(?![\w\x{2019}\'-])/iu';

        return preg_match($pattern, $haystack) === 1;
    }

    private function sameWord(string $a, string $b): bool
    {
        return Str::lower(trim($a)) === Str::lower(trim($b));
    }

    private function shareStem(string $a, string $b): bool
    {
        $min = min(mb_strlen($a), mb_strlen($b));
        if ($min < self::STEM_LENGTH) {
            return false;
        }

        return mb_substr($a, 0, self::STEM_LENGTH) === mb_substr($b, 0, self::STEM_LENGTH);
    }

    /** @return array<int, string> */
    private function contentWords(?string $text): array
    {
        $words = preg_split('/[^\p{L}]+/u', Str::lower(trim((string) $text)), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_filter(
            $words,
            fn ($w) => mb_strlen($w) > 2 && ! in_array($w, self::STOPWORDS, true),
        )));
    }
}
