<?php

namespace App\Services\Content;

/**
 * Turns a printed page into something worth reading on a screen.
 *
 * The `source_text` block was storing the page as pdftohtml emitted it: the
 * printed line numbers each on a line of their own, every bolded term split out
 * onto its own line, the inline glosses still in their square brackets. Rendered
 * into a single text view, that is what a learner saw - a column of fragments
 * where the book has prose. It was the worst-looking screen in the app and the
 * fault was in the data, not the styling.
 *
 * What comes back here is the page as paragraphs, with the words the lesson
 * teaches located inside them by character offset and carrying their gloss. That
 * is what lets the reading screen set the text as prose and still make each
 * taught word tappable, instead of printing a glossary underneath and hoping the
 * learner connects the two.
 */
class LessonReadingBuilder
{
    /** Reading speed for a learner working in a second language. */
    private const WORDS_PER_MINUTE = 90;

    public function __construct(
        private SourceSentenceMiner $miner,
        private SentenceQuality $quality,
    ) {}

    /**
     * @param  array<int, array{concept_id: int, term: string, gloss: ?string,
     *     meanings: array<string, string>}>  $taught
     * @return array{
     *     paragraphs: array<int, array{text: string, terms: array<int, array<string, mixed>>}>,
     *     word_count: int,
     *     estimated_seconds: int,
     *     glossed_terms: int
     * }|null
     */
    public function build(?string $pageText, array $taught): ?array
    {
        $glosses = array_values(array_filter(array_map(
            fn ($t) => $t['gloss'] ?? null,
            $taught,
        )));

        $paragraphs = $this->miner->paragraphs($pageText, $glosses);

        if ($paragraphs === []) {
            return null;
        }

        $words = 0;
        $glossed = 0;
        $out = [];

        foreach ($paragraphs as $paragraph) {
            $terms = $this->locate($paragraph, $taught);
            $glossed += count($terms);
            $words += str_word_count($paragraph);

            $out[] = ['text' => $paragraph, 'terms' => $terms];
        }

        return [
            'paragraphs' => $out,
            'word_count' => $words,
            'estimated_seconds' => max(20, (int) round($words / self::WORDS_PER_MINUTE * 60)),
            'glossed_terms' => $glossed,
        ];
    }

    /**
     * Where each taught word sits in this paragraph.
     *
     * Offsets are in UTF-8 characters, not bytes, because the client counts in
     * characters when it slices the string for a rich-text span. Longer terms
     * are matched first so "make eye contact" wins over "contact", and a term
     * already covered by a longer match is skipped rather than nested inside it.
     *
     * @param  array<int, array{concept_id: int, term: string, gloss: ?string}>  $taught
     * @return array<int, array<string, mixed>>
     */
    private function locate(string $paragraph, array $taught): array
    {
        usort($taught, fn ($a, $b) => mb_strlen($b['term']) <=> mb_strlen($a['term']));

        $found = [];
        $claimed = [];

        foreach ($taught as $entry) {
            $term = trim((string) $entry['term']);
            if ($term === '' || ! $this->quality->containsTerm($paragraph, $term)) {
                continue;
            }

            preg_match_all($this->pattern($term), $paragraph, $matches, PREG_OFFSET_CAPTURE);

            foreach ($matches[0] as $match) {
                $start = mb_strlen(substr($paragraph, 0, $match[1]));
                $end = $start + mb_strlen($match[0]);

                if ($this->overlaps($claimed, $start, $end)) {
                    continue;
                }

                $claimed[] = [$start, $end];
                $found[] = [
                    'concept_id' => $entry['concept_id'],
                    'term' => $match[0],
                    'start' => $start,
                    'end' => $end,
                    'gloss' => $entry['gloss'],
                    // The word in the learner's own language, by language code.
                    // Carried per word rather than fetched per tap: the reader
                    // works offline once the lesson is open.
                    'meanings' => $entry['meanings'] ?? [],
                ];

                // One highlight per word per paragraph: marking every repeat
                // turns the page into a field of underlines.
                break;
            }
        }

        usort($found, fn ($a, $b) => $a['start'] <=> $b['start']);

        return $found;
    }

    /** @param  array<int, array{0: int, 1: int}>  $claimed */
    private function overlaps(array $claimed, int $start, int $end): bool
    {
        foreach ($claimed as [$from, $to]) {
            if ($start < $to && $end > $from) {
                return true;
            }
        }

        return false;
    }

    private function pattern(string $term): string
    {
        $parts = preg_split('/\s+/u', trim($term)) ?: [];

        return '/(?<![\w\x{2019}\'-])'
            .implode('\s+', array_map(fn ($p) => preg_quote($p, '/'), $parts))
            .'(?![\w\x{2019}\'-])/iu';
    }
}
