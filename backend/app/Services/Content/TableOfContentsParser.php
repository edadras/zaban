<?php

namespace App\Services\Content;

/**
 * Recovers the thematic grouping the books actually use, from their contents pages.
 *
 * Units were imported grouped mechanically - "Units 1-10", "Units 11-20" - which
 * is an artefact of import order and teaches nothing. The books themselves group
 * their units under real categories: Work and study, People and relationships,
 * The environment. Those categories are what a learner navigates by, and they
 * exist only in the contents pages.
 *
 * The parse has to cope with a two-column layout that the PDF extractor
 * flattened into single lines, unit titles wrapping across two or three lines,
 * and one book that prints no page numbers at all. The discriminator that makes
 * it tractable is indentation: a category heading sits flush at the start of its
 * column, while a wrapped title is always indented under the number it belongs to.
 */
class TableOfContentsParser
{
    /** Front and back matter that looks like a heading but is not a category. */
    private const NOT_A_CATEGORY = [
        'contents', 'thanks', 'introduction', 'acknowledgements', 'index', 'key',
        'answer key', 'phonemic symbols', 'how to use this book', 'to the student',
        'to the teacher', 'appendix', 'glossary', 'pronunciation',
    ];

    /**
     * A page needs this many unit entries before it counts as part of the
     * contents. Without it the parse runs on into the introduction, where stray
     * numbered lines invent categories spanning impossible unit ranges.
     */
    private const MIN_UNIT_ENTRIES = 8;

    /**
     * @param  list<string>  $pages  candidate page texts, in book order
     * @param  int  $maxUnit  highest real unit number, used to reject noise
     * @return list<array{theme:string, first_unit:int}> ordered by first unit
     */
    public function parse(array $pages, int $maxUnit): array
    {
        $firstSeen = [];
        $theme = null;

        foreach ($pages as $text) {
            $lines = preg_split('/\R/u', $text);
            $columns = $this->columns($lines, $this->columnBoundary($lines, $maxUnit));

            // Counted after dedenting, because the continuation pages are
            // indented and would otherwise look like they hold no entries at all.
            if ($this->unitEntryCount(array_merge(...$columns)) < self::MIN_UNIT_ENTRIES) {
                // Past the end of the contents; anything further is prose.
                break;
            }

            foreach ($columns as $column) {
                foreach ($column as $line) {
                    $trimmed = trim($line);

                    if ($trimmed === '') {
                        continue;
                    }

                    if (preg_match('/^\s{0,3}(\d{1,3})\s{1,6}\S/u', $line, $m)) {
                        $unit = (int) $m[1];

                        if ($theme !== null && $unit >= 1 && $unit <= $maxUnit) {
                            $firstSeen[$theme] ??= $unit;
                        }

                        continue;
                    }

                    if ($this->isCategoryHeading($line, $trimmed)) {
                        $theme = $trimmed;
                    }
                }
            }
        }

        $themes = [];

        foreach ($firstSeen as $name => $first) {
            $themes[] = ['theme' => $name, 'first_unit' => $first];
        }

        usort($themes, fn ($a, $b) => $a['first_unit'] <=> $b['first_unit']);

        return $themes;
    }

    /**
     * Does this parse actually describe the book?
     *
     * The four books lay their contents out differently enough that a parse can
     * come back plausible-looking and wrong - a category starting at unit 4 that
     * belongs at 47, or one category swallowing eighty units. Shipping that is
     * worse than leaving the mechanical grouping in place, because a wrong
     * category is a navigational lie while "Units 1-10" is merely dull. So a
     * parse that fails these checks is discarded and the caller leaves the book
     * alone.
     *
     * @param  list<array{theme:string, first_unit:int}>  $themes
     */
    public function isCoherent(array $themes, int $maxUnit): bool
    {
        if (count($themes) < 3) {
            return false;
        }

        // The first category must open the book, not start a third of the way in.
        if ($themes[0]['first_unit'] > 3) {
            return false;
        }

        $previous = 0;

        foreach ($themes as $theme) {
            // Two categories claiming the same starting unit means the column
            // split or the heading detection went wrong.
            if ($theme['first_unit'] <= $previous) {
                return false;
            }

            $previous = $theme['first_unit'];
        }

        // No single category may dominate: that is the signature of a heading
        // that was missed, with everything after it falling into its lap.
        $largest = 0;

        foreach ($themes as $i => $theme) {
            $end = $themes[$i + 1]['first_unit'] ?? ($maxUnit + 1);
            $largest = max($largest, $end - $theme['first_unit']);
        }

        return $largest <= (int) ceil($maxUnit * 0.4);
    }

    private function unitEntryCount(array $lines): int
    {
        $n = 0;

        foreach ($lines as $line) {
            if (preg_match('/^\s{0,3}\d{1,3}\s{1,6}\S/u', $line)) {
                $n++;
            }
        }

        return $n;
    }

    /**
     * Where the right-hand column starts.
     *
     * Picking the widest or most frequent run of whitespace is not enough: on
     * the continuation pages the left column's page-number field opens a gap of
     * its own, and choosing that one splits the page mid-entry, hands the right
     * column a list of page numbers, and empties the left column entirely.
     *
     * So every plausible gap is tried and scored against what a correct split
     * must produce - a run of unit numbers that ascends and stays inside the
     * book. The wrong boundary yields page numbers in the hundreds and no order;
     * the right one yields 1, 2, 3.
     */
    private function columnBoundary(array $lines, int $maxUnit): int
    {
        $best = 50;
        $bestScore = -1;

        foreach ($this->candidateBoundaries($lines) as $candidate) {
            $score = $this->scoreBoundary($lines, $candidate, $maxUnit);

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $candidate;
            }
        }

        return $best;
    }

    /** @return list<int> */
    private function candidateBoundaries(array $lines): array
    {
        $gaps = [];

        foreach ($lines as $line) {
            if (preg_match_all('/\s{4,}/u', $line, $m, PREG_OFFSET_CAPTURE)) {
                foreach ($m[0] as $match) {
                    $end = $match[1] + strlen($match[0]);

                    if ($end > 30 && $end < 70) {
                        $gaps[$end] = ($gaps[$end] ?? 0) + 1;
                    }
                }
            }
        }

        $candidates = array_keys(array_filter($gaps, fn ($n) => $n >= 2));
        $candidates[] = 50;

        return array_values(array_unique($candidates));
    }

    /**
     * How well this split reads as a contents page: the length of the longest
     * ascending run of in-range unit numbers across both columns.
     */
    private function scoreBoundary(array $lines, int $boundary, int $maxUnit): int
    {
        $score = 0;

        foreach ($this->columns($lines, $boundary) as $column) {
            $run = 0;
            $previous = 0;

            foreach ($column as $line) {
                if (! preg_match('/^\s{0,3}(\d{1,3})\s{1,6}\S/u', $line, $m)) {
                    continue;
                }

                $unit = (int) $m[1];

                if ($unit >= 1 && $unit <= $maxUnit && $unit > $previous) {
                    $run++;
                    $previous = $unit;
                } else {
                    $score = max($score, $run);
                    $run = 0;
                    $previous = 0;
                }
            }

            $score = max($score, $run);
        }

        return $score;
    }

    /** Left column entirely, then right column - the order they are read in. */
    private function columns(array $lines, int $boundary): array
    {
        $left = [];
        $right = [];

        foreach ($lines as $line) {
            $left[] = substr($line, 0, $boundary);
            $right[] = substr($line, $boundary) ?: '';
        }

        return [$this->dedent($left), $this->dedent($right)];
    }

    /**
     * Strip the column's own left margin.
     *
     * Indentation is what tells a category heading from a wrapped title, but it
     * is only meaningful relative to where the column starts - and the books
     * indent their continuation pages four spaces further than their first. Read
     * absolutely, every heading on those pages looked like a wrapped title, and
     * the last category of each book swallowed the remaining fifty units.
     *
     * @param  list<string>  $column
     * @return list<string>
     */
    private function dedent(array $column): array
    {
        $margin = PHP_INT_MAX;

        foreach ($column as $line) {
            if (trim($line) !== '') {
                $margin = min($margin, strlen($line) - strlen(ltrim($line)));
            }
        }

        if ($margin === PHP_INT_MAX || $margin === 0) {
            return $column;
        }

        return array_map(
            fn ($line) => trim($line) === '' ? $line : substr($line, $margin),
            $column,
        );
    }

    private function isCategoryHeading(string $line, string $trimmed): bool
    {
        // Flush left within its column. This is the whole trick: a wrapped unit
        // title is indented under its number, a heading never is.
        if (strlen($line) - strlen(ltrim($line)) > 1) {
            return false;
        }

        $length = mb_strlen($trimmed);

        if ($length < 3 || $length > 45) {
            return false;
        }

        // Categories are wordy labels, never numbered and never page references.
        if (preg_match('/\d/u', $trimmed)) {
            return false;
        }

        if (! preg_match('/^\p{Lu}/u', $trimmed)) {
            return false;
        }

        return ! in_array(mb_strtolower($trimmed), self::NOT_A_CATEGORY, true);
    }
}
