<?php

namespace App\Services\Content;

/**
 * Recovers whole sentences from a book page.
 *
 * The `examples` table holds what the importer could isolate as an example, and
 * only about a fifth of those survive the quality gate - the rest were cut at a
 * column edge or start mid-clause. But the page itself is in the database in
 * full, and the page is written in sentences.
 *
 * The obstacle is layout, not language. pdftohtml emits the page as runs: the
 * printed line numbers land on lines of their own, and every bolded headword is
 * split out, so "make eye contact" arrives as three fragments on four lines.
 * Rejoining the runs and dropping the numbering gives back the prose, and the
 * prose is where the usable example sentences are.
 */
class SourceSentenceMiner
{
    /**
     * Mined prose runs longer than the examples the importer isolated, and the
     * long tail is where the column splices live.
     */
    private const MAX_CHARS = 160;

    /** How far apart two gutter votes can be and still be the same gutter. */
    private const GUTTER_TOLERANCE = 4;

    /** Below this a paragraph is really a stray heading or a page number. */
    private const MIN_PARAGRAPH_CHARS = 40;

    /** A gloss shorter than this could coincide with ordinary wording. */
    private const GLOSS_MIN_CHARS = 12;

    public function __construct(private SentenceQuality $quality) {}

    /**
     * Every sentence on the page that a learner could be shown.
     *
     * The page's own margin glosses are the tell for a column splice. They are
     * printed beside the text, not in it, so a sentence that swallowed one was
     * assembled across the gutter and is not a sentence the book contains.
     *
     * @param  array<int, string>  $glosses  the margin notes printed on this page
     * @return array<int, string>
     */
    public function sentences(?string $pageText, array $glosses = []): array
    {
        $prose = $this->reflow($pageText);
        if ($prose === '') {
            return [];
        }

        $needles = $this->normalisedGlosses($glosses);

        $out = [];
        foreach ($this->split($prose) as $sentence) {
            if (mb_strlen($sentence) > self::MAX_CHARS) {
                continue;
            }
            // Any digit still standing after the markers were stripped is a
            // marker this page printed detached from its word. Numbers are rare
            // in these sentences and gloss markers are not, so the digit is the
            // safer thing to reject on than to guess at.
            if (preg_match('/\d/u', $sentence)) {
                continue;
            }
            if (! $this->quality->isUsableSentence($sentence)) {
                continue;
            }
            if ($this->swallowedAGloss($sentence, $needles)) {
                continue;
            }
            $out[] = $sentence;
        }

        return $out;
    }

    /**
     * @param  array<int, string>  $glosses
     * @return array<int, string>
     */
    private function normalisedGlosses(array $glosses): array
    {
        $out = [];
        foreach ($glosses as $g) {
            $n = $this->normalise((string) $g);
            if (mb_strlen($n) >= self::GLOSS_MIN_CHARS) {
                $out[] = $n;
            }
        }

        return $out;
    }

    /**
     * @param  array<int, string>  $needles
     */
    private function swallowedAGloss(string $sentence, array $needles): bool
    {
        if ($needles === []) {
            return false;
        }

        $hay = $this->normalise($sentence);

        foreach ($needles as $n) {
            if (str_contains($hay, $n)) {
                return true;
            }
        }

        return false;
    }

    private function normalise(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', mb_strtolower(
            (string) preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text),
        )));
    }

    /**
     * The page as paragraphs, in reading order.
     *
     * `sentences()` is for mining single lines to build items from; this is for
     * showing the page to a learner. The lesson block was storing the pdftohtml
     * runs instead - the printed line numbers on their own lines, every bolded
     * term split onto a line of its own - so the reading screen showed a column
     * of fragments where the book has prose.
     *
     * @param  array<int, string>  $glosses  the margin notes printed on this page
     * @return array<int, string>
     */
    public function paragraphs(?string $pageText, array $glosses = []): array
    {
        $prose = $this->reflow($pageText);
        if ($prose === '') {
            return [];
        }

        $needles = $this->normalisedGlosses($glosses);

        $out = [];
        foreach (explode("\n", $prose) as $chunk) {
            $chunk = trim((string) preg_replace('/\s{2,}/u', ' ', $chunk));

            if ($chunk === '' || mb_strlen($chunk) < self::MIN_PARAGRAPH_CHARS) {
                continue;
            }

            // A paragraph that swallowed a margin note was assembled across the
            // gutter; showing it would put the glossary inside the sentence.
            if ($this->swallowedAGloss($chunk, $needles)) {
                continue;
            }

            $out[] = $chunk;
        }

        return $out;
    }

    /**
     * The sentences on this page that use the term, shortest first.
     *
     * @return array<int, string>
     */
    public function sentencesUsing(?string $pageText, string $term, array $glosses = []): array
    {
        $found = array_values(array_filter(
            $this->sentences($pageText, $glosses),
            fn (string $s) => $this->quality->containsTerm($s, $term),
        ));

        usort($found, fn ($a, $b) => mb_strlen($a) <=> mb_strlen($b));

        return $found;
    }

    /**
     * A printed page back into prose.
     *
     * Feed this `source_pages.text`, which is pdftotext -layout output. Layout
     * mode keeps the page's geometry: a two-column page comes back with both
     * columns on the same physical line, separated by the gutter. Joining those
     * lines naively splices the columns together, which is what produced
     * "No previous experience is necessary opportunities for promotion and
     * career as full training will be given" - a sentence from the body text
     * with a line of the margin column driven through the middle of it.
     *
     * So the gutter is found first and the page is read one column at a time.
     * What comes off after that is the book's own apparatus: the superscript
     * numbers printed against the word they mark ("recruiting1"), the bracketed
     * inline glosses, and the section headings that would otherwise glue
     * themselves to the sentence below.
     */
    public function reflow(?string $text, bool $keepMarkers = false): string
    {
        $text = (string) preg_replace('/\[[^\]]{0,80}\]/u', ' ', (string) $text);

        $lines = preg_split('/\R/u', $text) ?: [];
        $gutter = $this->findGutter($lines);

        $columns = $gutter === null ? [$lines] : $this->cut($lines, $gutter);

        $prose = [];
        foreach ($columns as $column) {
            $prose[] = $this->readColumn($column, $keepMarkers);
        }

        // A newline between columns, never a space: the foot of one column
        // must not run into the head of the next.
        return trim(implode("\n", array_filter($prose)));
    }

    /**
     * Split the page at the gutter without cutting through a word.
     *
     * The gutter is one offset for the whole page, but a line here and there
     * runs past it - a wide heading, a full-width rule. Cutting those at the
     * offset anyway is what turned "software development" into "software
     * develo". A line that has ink on both sides of the cut is left whole in the
     * left column instead.
     *
     * @return array{0: array<int, string>, 1: array<int, string>}
     */
    private function cut(array $lines, int $gutter): array
    {
        $left = [];
        $right = [];

        foreach ($lines as $line) {
            if (mb_strlen($line) <= $gutter) {
                $left[] = $line;
                $right[] = '';

                continue;
            }

            // A gutter is a run of blank space, not the single space between two
            // words. Requiring blank on both sides of the cut keeps a full-width
            // line that merely happens to have a word boundary at the offset -
            // "...business| last year." - in one piece.
            $before = mb_substr($line, max(0, $gutter - 2), 2);

            if (trim($before) !== '') {
                $left[] = $line;
                $right[] = '';

                continue;
            }

            $left[] = mb_substr($line, 0, $gutter);
            $right[] = mb_substr($line, $gutter);
        }

        return [$left, $right];
    }

    /**
     * The character offset where a second column begins, or null if there is
     * only one.
     *
     * The vote is on where the right column *starts*, not on where the left one
     * ends: the left column has a ragged right edge, so its end lands on a
     * different offset line by line, while the right column is aligned and every
     * line agrees. Body prose has the odd double space; only a column boundary
     * repeats at the same offset down the page.
     */
    private function findGutter(array $lines): ?int
    {
        $content = array_values(array_filter($lines, fn ($l) => trim($l) !== ''));
        if (count($content) < 6) {
            return null;
        }

        $votes = [];
        foreach ($content as $line) {
            if (! preg_match_all('/\S( {4,})(?=\S)/u', $line, $m, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            foreach ($m[1] as $gap) {
                $startsAt = mb_strlen(substr($line, 0, $gap[1] + strlen($gap[0])));
                $votes[$startsAt] = ($votes[$startsAt] ?? 0) + 1;
            }
        }

        if ($votes === []) {
            return null;
        }

        // The right column is aligned, but its marker numbers sit a couple of
        // characters to the left of its prose, so the offsets arrive as a tight
        // cluster rather than one value. Group them before counting, and cut at
        // the left edge of the winning cluster so nothing of the right column is
        // left behind in the left one.
        ksort($votes);
        $offsets = array_keys($votes);

        $best = null;
        $bestWeight = 0;
        $groupStart = 0;
        $groupWeight = 0;

        foreach ($offsets as $i => $offset) {
            if ($i === 0 || $offset - $groupStart > self::GUTTER_TOLERANCE) {
                if ($groupWeight > $bestWeight) {
                    $bestWeight = $groupWeight;
                    $best = $groupStart;
                }
                $groupStart = $offset;
                $groupWeight = 0;
            }
            $groupWeight += $votes[$offset];
        }
        if ($groupWeight > $bestWeight) {
            $bestWeight = $groupWeight;
            $best = $groupStart;
        }

        // A real gutter runs down the page, not through three lines. The margin
        // column is sparse - it only speaks where a word needs glossing - so the
        // bar is agreement, not majority.
        if ($best === null || $bestWeight < max(4, (int) round(count($content) * 0.18))) {
            return null;
        }

        $offset = $best;

        return $offset;
    }

    /** One column of the page, read top to bottom, as prose. */
    private function readColumn(array $lines, bool $keepMarkers = false): string
    {
        $out = [];
        $count = count($lines);

        for ($i = 0; $i < $count; $i++) {
            $line = rtrim($lines[$i]);
            $trimmed = trim($line);

            $isBareNumber = preg_match('/^\d{1,3}$/', $trimmed) === 1;

            // A line holding nothing but a number is the printed line number
            // in prose, and a footnote marker in a glossary block. Which one
            // depends on what the caller is reading the page for.
            if ($keepMarkers && $isBareNumber) {
                $out[] = $trimmed;

                continue;
            }

            if ($trimmed === '' || $isBareNumber) {
                // A blank line is the page's own paragraph break. Dropping it
                // outright, as this did, welds the page into one slab of text.
                if ($trimmed === '' && $out !== [] && end($out) !== "\n") {
                    $out[] = "\n";
                }

                continue;
            }

            $out[] = $trimmed;

            // A short line with no punctuation, followed by a new capitalised
            // line, is a heading. Break the paragraph so it does not glue itself
            // onto the sentence beneath it.
            $next = $this->nextContent($lines, $i);
            if ($next !== null
                && mb_strlen($trimmed) < 45
                && ! preg_match('/[.!?,;:\x{2019}]$/u', $trimmed)
                && preg_match('/^[A-Z]/u', $next)) {
                $out[] = "\n";
            }
        }

        $joined = implode(' ', $out);

        // "recruiting1" is the word plus its gloss marker, not a word - unless
        // the marker is the very thing being read for.
        if (! $keepMarkers) {
            $joined = (string) preg_replace('/(\p{L}{2,})\d{1,2}(?=[\s,.;:!?)]|$)/u', '$1', $joined);
        }

        // Punctuation that followed a bolded word kept the run's leading space.
        $joined = (string) preg_replace('/\s+([,.;:!?)\x{2019}])/u', '$1', $joined);
        $joined = (string) preg_replace('/([(\x{2018}])\s+/u', '$1', $joined);

        return trim((string) preg_replace('/[ \t]{2,}/u', ' ', $joined));
    }

    private function nextContent(array $lines, int $from): ?string
    {
        for ($j = $from + 1, $n = count($lines); $j < $n; $j++) {
            $candidate = trim($lines[$j]);
            if ($candidate !== '' && ! preg_match('/^\d{1,3}$/', $candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function split(string $prose): array
    {
        // A sentence ends at a full stop whatever follows it - insisting on a
        // capital next left "This will help boost your confidence." joined to
        // the margin note printed under it. Titles keep their full stop through
        // the split by holding it out of reach first.
        $masked = (string) preg_replace(
            '/\b(Mr|Mrs|Ms|Dr|Prof|St|Jr|Sr|vs|No)\./u',
            "$1\x00",
            $prose,
        );

        $parts = preg_split('/\n|(?<=[.!?])\s+/u', $masked, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_map(fn ($p) => trim(str_replace("\x00", '.', $p)), $parts);
    }
}
