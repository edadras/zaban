<?php

namespace Tests\Feature\Content;

use Tests\TestCase;

/**
 * The hand-written first-language catalogue.
 *
 * Every entry here is read by a learner who cannot check it: the whole point of
 * the meaning is that the English is not yet understood. A wrong entry is worse
 * than a missing one, and the failure modes of writing several thousand of them
 * by hand are mechanical rather than linguistic - a line left in English, an
 * empty value, the same key twice in a different case. Those are what is
 * checked here. Whether "خرامیدن" is the right word for "strut" is a question
 * for a reader, not for a test.
 */
class TranslationCatalogueTest extends TestCase
{
    /** @return array<string, string> */
    private function catalogue(string $code = 'fa'): array
    {
        $path = base_path("../docs/data/translations/{$code}.json");

        if (! is_file($path)) {
            $this->markTestSkipped("No {$code} catalogue in this checkout.");
        }

        return json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_the_catalogue_is_substantial(): void
    {
        $this->assertGreaterThan(9000, count($this->catalogue()));
    }

    public function test_every_entry_carries_a_meaning(): void
    {
        $empty = [];

        foreach ($this->catalogue() as $headword => $meaning) {
            if (trim((string) $meaning) === '') {
                $empty[] = $headword;
            }
        }

        $this->assertSame([], array_slice($empty, 0, 10), 'headwords with no meaning');
    }

    /**
     * A meaning written in Persian script.
     *
     * The catalogue is typed by hand into a file of mostly-English keys, and the
     * mistake that hides is a value that was never translated - the English
     * copied across, or a transliteration. Latin letters are allowed inside a
     * meaning, because some meanings need them ("ام‌پی‌تری" is fine but "IELTS"
     * belongs as itself), but a value with no Persian in it at all is not a
     * translation.
     */
    public function test_every_meaning_is_written_in_persian(): void
    {
        $bad = [];

        foreach ($this->catalogue() as $headword => $meaning) {
            if (! preg_match('/\p{Arabic}/u', (string) $meaning)) {
                $bad[] = "{$headword} => {$meaning}";
            }
        }

        $this->assertSame([], array_slice($bad, 0, 10), 'meanings with no Persian in them');
    }

    /** A meaning that repeats its own headword teaches nothing. */
    public function test_no_meaning_merely_echoes_the_english(): void
    {
        $bad = [];

        foreach ($this->catalogue() as $headword => $meaning) {
            if (mb_strtolower(trim((string) $meaning)) === mb_strtolower(trim((string) $headword))) {
                $bad[] = $headword;
            }
        }

        $this->assertSame([], array_slice($bad, 0, 10));
    }

    /**
     * Keys are matched case-insensitively at import, so two spellings of one
     * headword are two meanings racing for the same senses and the loser is
     * whichever the loop reaches first.
     */
    public function test_no_headword_appears_twice(): void
    {
        $seen = [];
        $clashes = [];

        foreach (array_keys($this->catalogue()) as $headword) {
            $key = mb_strtolower(trim((string) $headword));
            if (isset($seen[$key])) {
                $clashes[] = "{$seen[$key]} / {$headword}";
            }
            $seen[$key] = $headword;
        }

        $this->assertSame([], array_slice($clashes, 0, 10));
    }

    /**
     * Keys carry no surrounding space and no trailing punctuation: they are
     * matched against the corpus verbatim, so an entry that does is an entry
     * that silently reaches nothing.
     */
    public function test_headwords_are_written_as_the_corpus_writes_them(): void
    {
        $bad = [];

        foreach (array_keys($this->catalogue()) as $headword) {
            $h = (string) $headword;
            if ($h !== trim($h) || preg_match('/[,;]$/u', $h)) {
                $bad[] = "\"{$h}\"";
            }
        }

        $this->assertSame([], array_slice($bad, 0, 10));
    }

    /**
     * Polysemy is written the way a bilingual dictionary prints it, and the
     * separator has to be the one the reader expects: an Arabic semicolon
     * between meanings, not a Latin one sitting inside right-to-left text.
     */
    public function test_meanings_are_separated_the_persian_way(): void
    {
        $bad = [];

        foreach ($this->catalogue() as $headword => $meaning) {
            if (str_contains((string) $meaning, ';')) {
                $bad[] = "{$headword} => {$meaning}";
            }
        }

        $this->assertSame([], array_slice($bad, 0, 10), 'meanings separated with a Latin semicolon');
    }
}
