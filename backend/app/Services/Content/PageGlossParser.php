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
 *
 * Within a section. A page carries two or three of them, each numbering its own
 * footnotes from one, and reading the page whole let a section's terms take the
 * next section's list: "cram" was given the gloss the book prints against "mind
 * map". Slicing the page to the lesson's own lettered section first is what
 * makes the numbering mean one thing, because that is the scope the book
 * numbers within.
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
     * @param  ?string  $section  the lettered section this lesson is, if the book prints one
     * @return array{glosses: array<string, string>, strip: array<int, string>}
     */
    public function parse(?string $pageText, array $terms, ?string $section = null): array
    {
        // The book numbers its footnotes within a section, so that is the
        // scope to read in. Taking the whole page let section A's terms be
        // paired against section B's list, number for number, and every gloss
        // in it landed on the wrong word.
        $pageText = $this->sectionOf($pageText, $section, $terms);

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

        $apparatus = $this->apparatus($flat, $markers);
        $highest = max(array_keys($markers));

        // The list as the book sets it, read in order from its "1".
        $region = $this->listStart($apparatus);
        [$entries, $consumed] = $region === null
            ? [[], '']
            : $this->entries($region, $highest);

        // What is left is the rest of the section's apparatus: a margin column
        // the reflow moved, or a run the list ran out before. Section C of a
        // page prints 1-4 in the margin and 5-8 at the foot, and reading only
        // forwards from the "1" reached four of the eight.
        [$entries, $alsoStrip] = $this->gaps($apparatus, $entries, $highest);

        if ($entries === []) {
            return ['glosses' => [], 'strip' => []];
        }

        $glosses = [];
        foreach ($entries as $number => $gloss) {
            if (isset($markers[$number])) {
                $glosses[$markers[$number]] = $gloss;
            }
        }

        return [
            'glosses' => $glosses,
            // The footnote block is apparatus, not prose, and so is a margin
            // column the reflow left in the tail. Handing them back lets the
            // reading view cut them out instead of printing the glossary as
            // the tail of a paragraph.
            'strip' => array_values(array_filter(
                array_merge([$consumed], $alsoStrip),
                fn ($piece) => trim((string) $piece) !== '',
            )),
        ];
    }

    /**
     * The page cut back to one lettered section.
     *
     * The books set a section as a capital letter standing alone in the left
     * margin, then its title: "A   Study and exams". Everything up to the next
     * such letter is that section, margin notes and footnote list included,
     * which is exactly the run the section's numbering covers.
     *
     * Only taken when it holds up: the heading has to be there, and the
     * lesson's own words have to be inside what it cuts. A page the scanner set
     * differently, or a book that does not letter its sections at all, is read
     * whole as before - a smaller scope read wrongly is worse than a wide one.
     *
     * @param  array<int, string>  $terms
     */
    private function sectionOf(?string $pageText, ?string $section, array $terms): ?string
    {
        $letter = strtoupper(trim((string) $section));

        if ($pageText === null || ! preg_match('/^[A-Z]$/', $letter)) {
            return $pageText;
        }

        $heading = '/^[ \t]*'.$letter.'[ \t]{2,}(?=\p{L})/mu';
        if (! preg_match($heading, $pageText, $m, PREG_OFFSET_CAPTURE)) {
            return $pageText;
        }

        $from = $m[0][1];
        $rest = substr($pageText, $from + strlen($m[0][0]));

        $slice = $rest;
        if (preg_match('/^[ \t]*[A-Z][ \t]{2,}(?=\p{L})/mu', $rest, $next, PREG_OFFSET_CAPTURE)) {
            $slice = substr($rest, 0, $next[0][1]);
        }

        return $this->holds($slice, $terms) ? $slice : $pageText;
    }

    /** Does this slice actually contain the lesson it is supposed to be? */
    private function holds(string $slice, array $terms): bool
    {
        $flat = (string) preg_replace('/\s+/u', ' ', $slice);

        foreach ($terms as $term) {
            $term = trim((string) $term);
            if ($term === '') {
                continue;
            }

            $words = preg_split('/\s+/u', $term) ?: [];
            $pattern = '/(?<![\w\x{2019}\'-])'
                .implode('\s+', array_map(fn ($w) => preg_quote($w, '/'), $words))
                .'(?![\w])/iu';

            if (preg_match($pattern, $flat)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Which marker number each taught term carries, from the body text.
     *
     * Driven by the term list rather than by pattern-matching words before
     * digits: the marker attaches to the last word of the term, so "trial run4"
     * only reads as a marker on "trial run" if you already know that is a term.
     *
     * @param  array<int, string>  $terms
     * @return array<int, string> marker number => term
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
     * The section's apparatus: everything past the last marked word.
     *
     * The prose ends at the last term the book marked, and what follows is the
     * footnote list, any margin column the reflow moved down here, and the page
     * furniture between them.
     *
     * @param  array<int, string>  $markers
     */
    private function apparatus(string $flat, array $markers): string
    {
        $lastTerm = end($markers);
        $lastNumber = array_key_last($markers);

        $words = preg_split('/\s+/u', (string) $lastTerm) ?: [];
        $pattern = '/(?<![\w\x{2019}\'-])'
            .implode('\s+', array_map(fn ($w) => preg_quote($w, '/'), $words))
            .preg_quote((string) $lastNumber, '/').'(?![\d\w])/iu';

        if (! preg_match($pattern, $flat, $m, PREG_OFFSET_CAPTURE)) {
            return $flat;
        }

        return substr($flat, $m[0][1] + strlen($m[0][0]));
    }

    /** The list proper, from its first standalone "1" followed by words. */
    private function listStart(string $apparatus): ?string
    {
        if (! preg_match('/(?<![\w.])1\s+(?=\p{L})/u', $apparatus, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        return substr($apparatus, $m[0][1]);
    }

    /**
     * Numbers the ordered read did not reach, looked for on their own.
     *
     * Kept to the gaps deliberately. Reading each number wherever it appears is
     * how a second block is found, but it is also how a number inside the prose
     * gets mistaken for an entry, so the ordered read stays in charge of
     * everything it did reach.
     *
     * @param  array<int, string>  $entries
     * @return array{0: array<int, string>, 1: array<int, string>} entries, and the text they consumed
     */
    private function gaps(string $apparatus, array $entries, int $highest): array
    {
        $strip = [];

        for ($n = 1; $n <= $highest; $n++) {
            if (isset($entries[$n])) {
                continue;
            }

            $bounded = '/(?<![\w.])'.$n.'\s+(\p{L}.*?)(?=\s\d{1,2}\s)/su';
            $open = '/(?<![\w.])'.$n.'\s+(\p{L}.*)$/su';

            if (! preg_match($bounded, $apparatus, $m) && ! preg_match($open, $apparatus, $m)) {
                continue;
            }

            $gloss = $this->trimGloss($m[1]);
            if ($gloss !== null) {
                $entries[$n] = $gloss;
                $strip[] = $m[0];
            }
        }

        ksort($entries);

        return [$entries, $strip];
    }

    /**
     * Split "1 gloss 2 gloss 3 gloss" into its entries.
     *
     * Each entry runs to the next number in sequence. The last has no successor
     * to stop it, so it is cut where the page moves on - a new section letter, a
     * speaker label, or simply the length beyond which this is no longer a
     * gloss.
     *
     * @return array{0: array<int, string>, 1: string} entries, and the text they consumed
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
