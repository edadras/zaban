<?php

namespace Tests\Feature\Content;

use Tests\TestCase;

/**
 * The books' own drills, paired with the answers their keys print.
 *
 * These are the first questions in the project that come from a printed page
 * rather than from a disc, and they are built by pairing two numbered lists
 * that sit a hundred pages apart. A pairing that slips by one gives every item
 * its neighbour's answer, and every one of those marks a right answer wrong -
 * which teaches the opposite of the truth and is invisible until a learner
 * meets it.
 *
 * So these read the extracted data and check the properties that decide
 * whether an item is safe to ask.
 */
class DrillItemSoundnessTest extends TestCase
{
    private const BLANK = '______';

    /** @return list<array{0:array,1:string}> item, and where it came from */
    private function items(): array
    {
        $dir = base_path('../docs/data/curriculum');
        $files = glob($dir.'/*.json') ?: [];
        if ($files === []) {
            $this->markTestSkipped('The curriculum has not been extracted in this checkout.');
        }

        $out = [];
        foreach ($files as $file) {
            $book = basename($file, '.json');
            $data = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            foreach ($data['units'] as $unit) {
                foreach ($unit['exercises'] as $drill) {
                    foreach ($drill['items'] ?? [] as $item) {
                        $out[] = [$item, "{$book} {$unit['number']}.{$drill['number']}.{$item['number']}"];
                    }
                }
            }
        }

        if ($out === []) {
            $this->markTestSkipped('No drill items have been mined in this checkout.');
        }

        return $out;
    }

    public function test_the_books_yield_a_bank_worth_having(): void
    {
        $this->assertGreaterThan(1_500, count($this->items()));
    }

    /**
     * One gap, or the learner cannot tell what is being asked of them.
     */
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

    public function test_every_item_states_an_answer_a_learner_could_type(): void
    {
        $bad = [];

        foreach ($this->items() as [$item, $where]) {
            $answers = $item['answers'] ?? [];
            if ($answers === []) {
                $bad[] = "{$where}: no answer";

                continue;
            }
            foreach ($answers as $answer) {
                if (trim($answer) === '' || mb_strlen($answer) > 80) {
                    $bad[] = "{$where}: ".mb_substr($answer, 0, 40);
                }
            }
        }

        $this->assertSame([], array_slice($bad, 0, 5), 'answers nobody could give');
    }

    /**
     * The wrong options are other items' answers from the same drill. That is
     * what makes them provably wrong - but only while they stay other items'
     * answers: an option equal to this item's own answer marks the learner
     * wrong for choosing correctly.
     */
    public function test_no_option_is_secretly_the_right_answer(): void
    {
        $bad = [];

        foreach ($this->items() as [$item, $where]) {
            $answers = array_map($this->normalise(...), $item['answers'] ?? []);
            foreach ($item['options'] ?? [] as $option) {
                if (in_array($this->normalise($option), $answers, true)) {
                    $bad[] = "{$where}: \"{$option}\"";
                }
            }
        }

        $this->assertSame([], array_slice($bad, 0, 5), 'wrong options that are right');
    }

    /**
     * An option already printed in the sentence is not a wrong answer, it is
     * the answer shown to the learner before they choose.
     */
    public function test_no_option_is_given_away_by_the_sentence(): void
    {
        $bad = [];

        foreach ($this->items() as [$item, $where]) {
            $stem = $this->normalise($item['stem']);
            foreach ($item['options'] ?? [] as $option) {
                $normalised = $this->normalise($option);
                if ($normalised !== '' && str_contains($stem, $normalised)) {
                    $bad[] = "{$where}: \"{$option}\"";
                }
            }
        }

        $this->assertSame([], array_slice($bad, 0, 5), 'options the sentence already contains');
    }

    public function test_the_options_offered_are_all_different(): void
    {
        $bad = [];

        foreach ($this->items() as [$item, $where]) {
            $options = array_map($this->normalise(...), $item['options'] ?? []);
            if (count($options) !== count(array_unique($options))) {
                $bad[] = $where;
            }
        }

        $this->assertSame([], array_slice($bad, 0, 5), 'items offering the same option twice');
    }

    /**
     * A choice needs somewhere to go wrong. One wrong option and a coin
     * decides; the importer therefore only builds a choice item where there
     * are at least two, and everything else stays something to type.
     *
     * A drill of two or three parts cannot raise a choice out of its own
     * answers, so where its own do not suffice the rest of the unit's are
     * offered - the unit drills one point, and its answers are wrong here for
     * the same reason a sibling's is. That is what carries this count past
     * eighteen hundred rather than leaving four in five items typed.
     */
    public function test_a_choice_item_offers_more_than_one_wrong_answer(): void
    {
        $withChoices = 0;

        foreach ($this->items() as [$item, $where]) {
            $count = count($item['options'] ?? []);
            $this->assertNotSame(1, $count, "{$where} offers a single wrong option");
            if ($count >= 2) {
                $withChoices++;
            }
        }

        $this->assertGreaterThan(1_800, $withChoices, 'too few items can be asked as a choice');
    }

    /**
     * Three shapes reach the miner that are not questions, and each was found
     * by reading what it produced rather than by reasoning about it.
     *
     * A matching drill answers with a letter - "f", "h" - which means nothing
     * outside the printed grid it points into. A key note says what to leave
     * out rather than what to write: "– (Those are nice shoes.)". And an answer
     * with an item number inside it is two answers the column reader ran
     * together: "insolent 3 offhand".
     */
    public function test_no_answer_is_a_grid_reference_or_a_note_to_the_reader(): void
    {
        $bad = [];

        foreach ($this->items() as [$item, $where]) {
            foreach ($item['answers'] ?? [] as $answer) {
                $answer = trim($answer);
                // One character only. "is", "AD" and "e.g" are the answers to
                // real gaps in these books, and a rule that took anything
                // under three characters took those with it.
                if (mb_strlen($answer) <= 1) {
                    $bad[] = "{$where}: \"{$answer}\" is a grid reference, not an answer";
                }
                if (preg_match('/^[-–—]|\(\s*no\s+\w+/iu', $answer)) {
                    $bad[] = "{$where}: \"{$answer}\" is a note to the reader";
                }
                if (preg_match('/\S\s+\d{1,2}\s+\S/', $answer)) {
                    $bad[] = "{$where}: \"{$answer}\" is two answers run together";
                }
            }
        }

        $this->assertSame([], array_slice($bad, 0, 5));
    }

    /**
     * The last item in a column runs into the page furniture below it, and the
     * gap before that furniture then reads as the printed blank - so the item
     * was mined as a question whose answer went at the foot of the page.
     */
    public function test_no_stem_runs_into_the_foot_of_the_page(): void
    {
        $footer = '/\b(?:Vocabulary|Grammar|Pronunciation|Phrasal Verbs?|Collocations?|Idioms) in Use\b/i';
        $bad = [];

        foreach ($this->items() as [$item, $where]) {
            if (preg_match($footer, $item['stem'])) {
                $bad[] = "{$where}: {$item['stem']}";
            }
        }

        $this->assertSame([], array_slice($bad, 0, 5), 'stems carrying the running footer');
    }

    private function normalise(string $text): string
    {
        return trim(preg_replace('/[^a-z0-9 ]/', '', mb_strtolower($text)));
    }
}
