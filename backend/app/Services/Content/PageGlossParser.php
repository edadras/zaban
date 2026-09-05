<?php

namespace App\Services\Content;

/**
 * Recovers the glosses the books print in their own footnotes.
 *
 * These pages mark a taught word with a superscript number and print the
 * explanation at the foot of the section: "recruiting1 ... criteria2" in the
 * prose, and "1 hiring (new staff) 2 requirements you use to make a decision"
 * beneath it. The importer was reading margin notes but not these, so about
 * three quarters of the taught senses arrived with no definition at all.
 *
 * That shortage cost more than a blank field. DistractorPolicy can only prove a
 * wrong answer wrong when both words carry a definition, so every missing gloss
 * was also a choice item that could not be built, or could only be built on the
 * weaker evidence. And the footnote block itself, left in the page text, was
 * being read as part of the prose - the reading screen ended its first
 * paragraph with the entire glossary run together.
 *
 * Pairing is by number, which is what the book itself does, so it is exact
 * rather than approximate: the marker that follows a term is the entry that
 * explains it.
 */
class PageGlossParser
{
    public function __construct(private SourceSentenceMiner $miner) {}

    /** A gloss is a phrase. Anything longer is the page carrying on. */
    private const MAX_GLOSS_CHARS = 130;

    private const MIN_GLOSS_CHARS = 3;

    /** The books number footnotes within a section, never beyond this. */
    private const MAX_MARKER = 30;

    /**
     * @param  array<int, string>  $terms  the words this page teaches
     * @return array{glosses: array<string, string>, strip: array<int, string>}
     */
    public function parse(?string $pageText, array $terms): array
    {
        // Read through the miner so the page's columns are separated first.
        // A footnote block is often set in two columns, and read straight off
        // the line it interleaves: "1 ... 11 ... when everyone has the same
        // chances richer 2 ... 12 ...". Markers are kept, since here they are
        // the point rather than debris.
        $flat = trim((string) preg_replace(
            '/\s+/u',
            ' ',
            $this->miner->reflow($pageText, keepMarkers: true),
        ));

        if ($flat === '' || $terms === []) {
            return ['glosses' => [], 'strip' => []];
        }

        $markers = $this->markersInBody($flat, $terms);
        if ($markers === []) {
            return ['glosses' => [], 'strip' => []];
        }

        $region = $this->footnoteRegion($flat, $markers);
        if ($region === null) {
            return ['glosses' => [], 'strip' => []];
        }

        [$entries, $consumed] = $this->entries($region, max(array_keys($markers)));

        $glosses = [];
        foreach ($entries as $number => $gloss) {
            if (isset($markers[$number])) {
                $glosses[$markers[$number]] = $gloss;
            }
        }

        return [
            'glosses' => $glosses,
            // The footnote block is apparatus, not prose. Handing it back lets
            // the reading view cut it out instead of printing the glossary as
            // the tail of a paragraph.
            'strip' => $consumed === '' ? [] : [$consumed],
        ];
    }

    /**
     * Which marker number each taught term carries, from the body text.
     *
     * Driven by the term list rather than by pattern-matching words before
     * digits: the marker attaches to the last word of the term, so "trial run4"
     * only reads as a marker on "trial run" if you already know that is a term.
     *
     * @param  array<int, string>  $terms
     * @return array<int, string>  marker number => term
     */
    private function markersInBody(string $flat, array $terms): array
    {
        $markers = [];

        // Longest first: "run4" and "trial run4" are the same marker, and the
        // phrase is the term the book is glossing, not its last word.
        usort($terms, fn ($a, $b) => mb_strlen((string) $b) <=> mb_strlen((string) $a));

        foreach ($terms as $term) {
            $term = trim((string) $term);
            if ($term === '') {
                continue;
            }

            $words = preg_split('/\s+/u', $term) ?: [];
            $pattern = '/(?<![\w\x{2019}\'-])'
                .implode('\s+', array_map(fn ($w) => preg_quote($w, '/'), $words))
                .'(\d{1,2})(?![\d\w])/iu';

            if (! preg_match($pattern, $flat, $m)) {
                continue;
            }

            $number = (int) $m[1];
            if ($number < 1 || $number > self::MAX_MARKER) {
                continue;
            }

            // First term wins a contested number; the books do not reuse one
            // within a section, so a clash means one of the two is a false read.
            $markers[$number] ??= $term;
        }

        ksort($markers);

        return $markers;
    }

    /**
     * The text from the start of the footnote list onwards.
     *
     * The list begins after the last marked word in the prose, at the first
     * standalone "1" followed by words.
     *
     * @param  array<int, string>  $markers
     */
    private function footnoteRegion(string $flat, array $markers): ?string
    {
        $lastTerm = end($markers);
        $lastNumber = array_key_last($markers);

        $words = preg_split('/\s+/u', (string) $lastTerm) ?: [];
        $pattern = '/(?<![\w\x{2019}\'-])'
            .implode('\s+', array_map(fn ($w) => preg_quote($w, '/'), $words))
            .preg_quote((string) $lastNumber, '/').'(?![\d\w])/iu';

        $from = 0;
        if (preg_match($pattern, $flat, $m, PREG_OFFSET_CAPTURE)) {
            $from = $m[0][1] + strlen($m[0][0]);
        }

        $tail = substr($flat, $from);

        if (! preg_match('/(?<![\w.])1\s+(?=\p{L})/u', $tail, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        return substr($tail, $m[0][1]);
    }

    /**
     * Split "1 gloss 2 gloss 3 gloss" into its entries.
     *
     * Each entry runs to the next number in sequence. The last has no successor
     * to stop it, so it is cut where the page moves on - a new section letter, a
     * speaker label, or simply the length beyond which this is no longer a
     * gloss.
     *
     * @return array{0: array<int, string>, 1: string}  entries, and the text they consumed
     */
    private function entries(string $region, int $highest): array
    {
        $entries = [];
        $cursor = $region;
        $consumed = 0;

        for ($n = 1; $n <= $highest; $n++) {
            // Bounded by the next number in the list, whatever it is. Tying
            // the bound to n+1 specifically meant that a caller who did not
            // know every marker on the page - one term of five, say - let the
            // final entry run on and swallow the rest of the glossary.
            $bounded = '/(?<![\w.])'.$n.'\s+(.+?)(?=\s\d{1,2}\s)/su';
            $open = '/(?<![\w.])'.$n.'\s+(.+)$/su';

            if (! preg_match($bounded, $cursor, $m, PREG_OFFSET_CAPTURE)
                && ! preg_match($open, $cursor, $m, PREG_OFFSET_CAPTURE)) {
                break;
            }

            $gloss = $this->trimGloss($m[1][0]);
            if ($gloss !== null) {
                $entries[$n] = $gloss;
            }

            $advance = $m[0][1] + strlen($m[0][0]);
            $consumed += $advance;
            $cursor = substr($cursor, $advance);
        }

        return [$entries, $consumed > 0 ? substr($region, 0, $consumed) : ''];
    }

    /** Cut a captured entry back to the gloss itself. */
    private function trimGloss(string $raw): ?string
    {
        $gloss = trim($raw);

        // A section letter and title, or a printed speaker label, is the page
        // resuming - everything from there on belongs to the next section.
        if (preg_match('/\s(?:[A-Z]\s+[A-Z]|[A-Z]:\s)/u', $gloss, $m, PREG_OFFSET_CAPTURE)) {
            $gloss = trim(substr($gloss, 0, $m[0][1]));
        }

        if (mb_strlen($gloss) > self::MAX_GLOSS_CHARS) {
            $gloss = mb_substr($gloss, 0, self::MAX_GLOSS_CHARS);
            $gloss = (string) preg_replace('/\s+\S*$/u', '', $gloss);
        }

        $gloss = trim($gloss, " \t\n\r\0\x0B.,;:");

        return mb_strlen($gloss) >= self::MIN_GLOSS_CHARS ? $gloss : null;
    }
}
