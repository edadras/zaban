<?php

namespace App\Services\Content;

/**
 * Recovers two-speaker exchanges from a page of extracted PDF text.
 *
 * All the difficulty is in telling a real exchange apart from the surrounding
 * book furniture. These volumes use bare capital letters as section headings
 * ("A  Adjectives connected with size"), print single "A: ..." lines as isolated
 * examples, separate the turns of a real dialogue with blank lines, wrap long
 * turns onto unlabelled continuation lines, and flatten superscript footnote
 * markers into the words themselves. Each of those cost a measurable amount of
 * the corpus before it was handled: exact-page matching alone was discarding
 * nearly half of what was found.
 */
class DialogueParser
{
    /**
     * Find runs of consecutive speaker-labelled lines.
     *
     * A run needs at least two turns from at least two speakers, which is what
     * separates a real exchange from the books' single-line "A: ..." examples
     * and from their section letters (a bare "A" heading with no colon).
     *
     * @return list<list<array{speaker:string,text:string}>>
     */
    public function runsIn(string $text): array
    {
        $runs = [];
        $current = [];

        foreach (preg_split('/\R/u', $text) as $line) {
            if (preg_match('/^\s*([A-D]):\s+(\S.*)$/u', $line, $m)) {
                $clean = $this->cleanTurn($m[2]);

                if ($clean !== '') {
                    $current[] = ['speaker' => $m[1], 'text' => $clean];

                    continue;
                }
            }

            // The typesetting puts a blank line between turns. It separates
            // them; it does not end the exchange.
            if (trim($line) === '') {
                continue;
            }

            /*
             * A wrapped turn: the book breaks long speech across lines without
             * repeating the speaker label ("...took a job as a trainee at F3" /
             * "Telecom."). Only treat an unlabelled line as the rest of the
             * previous turn when that turn was genuinely cut off - if it ended
             * on a full stop or question mark it was complete, and what follows
             * is body text that should end the run instead of being swallowed
             * into someone's mouth.
             */
            if ($current !== [] && ! $this->looksFinished(end($current)['text'])) {
                $continuation = $this->cleanTurn($line);

                if ($continuation !== '' && ! preg_match('/^\s*[A-D]\s+[A-Z]/u', $line)) {
                    $current[count($current) - 1]['text'] .= ' '.$continuation;

                    continue;
                }
            }

            $runs[] = $current;
            $current = [];
        }

        $runs[] = $current;

        return array_values(array_filter($runs, fn ($r) => count($r) >= 2
            && count(array_unique(array_column($r, 'speaker'))) >= 2));
    }

    /** Did this turn end on a sentence boundary, or was it cut mid-line? */
    private function looksFinished(string $text): bool
    {
        return (bool) preg_match('/[.!?\x{2019}\x{201D}"\x{2026}]$/u', $text);
    }

    /**
     * The printed text carries superscript footnote markers that the PDF
     * extractor flattened into the words themselves - "talk us through1 your CV",
     * "a trainee2". A digit glued to the end of a lowercase word is always one of
     * those, never part of the sentence; digits after a space or a capital
     * ("11", "10.30", "F3") are real and left alone.
     */
    public function cleanTurn(string $text): string
    {
        $t = preg_replace('/(?<=[a-z])\d{1,2}(?!\d)/u', '', $text);

        // Bracketed glosses are the book explaining a word, not speech. They are
        // already captured as inline glosses during ingestion.
        $t = preg_replace('/\[[^\]]*\]/u', '', (string) $t);

        return trim(preg_replace('/\s+/u', ' ', (string) $t));
    }

}
