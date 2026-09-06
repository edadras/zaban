<?php

namespace Tests\Feature\Content;

use Tests\TestCase;

/**
 * The multiple-choice bank the grammar book prints at the back.
 *
 * These items are read off two runs of pages a few dozen apart - the guide and
 * its key - and paired by a printed number. Everything that can go wrong with
 * that pairing produces an item that looks fine and marks a right answer wrong,
 * so what is checked here is the shape of the result rather than the reading.
 *
 * The scanned grammars print the same guide and are deliberately not read: their
 * keys come back as "152 8B" and "20.2 €E".
 */
class StudyGuideSoundnessTest extends TestCase
{
    private const BLANK = '______';

    /** @return list<array{0:array,1:string}> */
    private function items(): array
    {
        $files = glob(base_path('../docs/data/curriculum').'/*.json') ?: [];
        if ($files === []) {
            $this->markTestSkipped('The curriculum has not been extracted in this checkout.');
        }

        $out = [];
        foreach ($files as $file) {
            $book = basename($file, '.json');
            $data = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            foreach ($data['study_guide'] ?? [] as $item) {
                $out[] = [$item, "{$book} {$item['number']}"];
            }
        }

        if ($out === []) {
            $this->markTestSkipped('No study guide has been read in this checkout.');
        }

        return $out;
    }

    public function test_the_book_yields_its_whole_guide(): void
    {
        $this->assertGreaterThan(140, count($this->items()));
    }

    public function test_every_item_asks_for_exactly_one_thing(): void
    {
        $bad = [];

        foreach ($this->items() as [$item, $where]) {
            if (substr_count($item['stem'], self::BLANK) !== 1) {
                $bad[] = "{$where}: {$item['stem']}";
            }
        }

        $this->assertSame([], array_slice($bad, 0, 5), 'items with no gap, or more than one');
    }

    /**
     * The guide prints between two and five alternatives, and says in capitals
     * on its first page that more than one may be right. What it never does is
     * make all of them right, or none.
     */
    public function test_every_item_offers_a_real_choice(): void
    {
        $bad = [];

        foreach ($this->items() as [$item, $where]) {
            $options = $item['options'];
            $right = array_filter($options, fn ($o) => $o['is_correct']);
            $texts = array_map(fn ($o) => mb_strtolower(trim($o['text'])), $options);

            if (count($options) < 2 || count($options) > 5) {
                $bad[] = "{$where}: ".count($options).' alternatives';
            }
            if ($right === [] || count($right) >= count($options)) {
                $bad[] = "{$where}: ".count($right).' of '.count($options).' are right';
            }
            if (in_array('', $texts, true)) {
                $bad[] = "{$where}: an alternative with no text";
            }
            if (count($texts) !== count(array_unique($texts))) {
                $bad[] = "{$where}: the same alternative twice";
            }
        }

        $this->assertSame([], array_slice($bad, 0, 5));
    }

    /**
     * The letters must run A, B, C … . A missed label swallows one alternative
     * into the one before it - "quite a good job C a pretty good job" - and the
     * learner is offered a choice the book never printed.
     */
    public function test_the_alternatives_are_labelled_in_order(): void
    {
        $bad = [];

        foreach ($this->items() as [$item, $where]) {
            $labels = array_column($item['options'], 'label');
            $expected = array_slice(['A', 'B', 'C', 'D', 'E'], 0, count($labels));
            if ($labels !== $expected) {
                $bad[] = "{$where}: ".implode('', $labels);
            }
            foreach ($item['options'] as $i => $option) {
                // A missed label seen from the other side: the alternative that
                // swallowed it carries the next letter inside its own text.
                // Only the letters that come after this one count - "A friend
                // of mine" is what the book printed, not a label.
                $next = array_slice(['A', 'B', 'C', 'D', 'E'], $i + 1);
                if ($next === []) {
                    continue;
                }
                if (preg_match('/(?:^|\s)['.implode('', $next).']\s+\S/u', ' '.$option['text'])) {
                    $bad[] = "{$where}: \"{$option['text']}\"";
                }
            }
        }

        $this->assertSame([], array_slice($bad, 0, 5));
    }

    /** The margin's unit numbers are what file the item under a lesson. */
    public function test_every_item_names_the_units_that_teach_it(): void
    {
        $bad = [];

        foreach ($this->items() as [$item, $where]) {
            $units = $item['units'] ?? [];
            if ($units === [] || array_filter($units, fn ($u) => ! is_int($u) || $u < 1)) {
                $bad[] = "{$where}: ".json_encode($units);
            }
        }

        $this->assertSame([], array_slice($bad, 0, 5), 'items that belong to no unit');
    }
}
