<?php

namespace Tests\Feature\Content;

use Tests\TestCase;

/**
 * The disc's exercises are the first items in this project that can be marked.
 *
 * Every exercise taken off a printed page imports as one row per instruction,
 * with its answers sixty pages away as prose, so none of them can be served.
 * These can: each states its own stem, its blanks and what fills them. That
 * makes them the first place where a learner can be told they are wrong - and
 * being told you are wrong when you were right is the one failure that teaches
 * the opposite of the truth.
 *
 * So these read the extracted data rather than the database, and check the
 * properties that decide whether an item is safe to ask.
 */
class DiscExerciseSoundnessTest extends TestCase
{
    private function disc(): array
    {
        $path = base_path('../docs/data/cdrom/grammar_advanced.json');
        if (! is_file($path)) {
            $this->markTestSkipped('The disc has not been extracted in this checkout.');
        }

        return json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return list<array{0:array,1:array}> item, its exercise */
    private function items(): array
    {
        $out = [];
        foreach ($this->disc()['exercises'] as $exercise) {
            foreach ($exercise['items'] as $item) {
                $out[] = [$item, $exercise];
            }
        }

        return $out;
    }

    public function test_the_disc_yields_a_bank_worth_having(): void
    {
        $disc = $this->disc();

        $this->assertGreaterThan(1_500, $disc['summary']['items']);
        $this->assertGreaterThan(
            0.9 * $disc['summary']['items'],
            $disc['summary']['markable_items'],
            'most of the disc should be markable; anything less means the parser lost answers',
        );
    }

    public function test_no_item_carries_an_empty_or_runaway_answer(): void
    {
        $bad = [];

        foreach ($this->items() as [$item, $exercise]) {
            foreach ($this->answersOf($item) as $answer) {
                // A correction's answer is the whole sentence put right, so it
                // is as long as the sentence; everything else is typed into a
                // gap and a gap that long is a parse gone wrong.
                $limit = $item['shape'] === 'correction' ? 400 : 200;
                if (trim($answer) === '' || mb_strlen($answer) > $limit) {
                    $bad[] = "{$exercise['file']} #{$item['position']}: ".mb_substr($answer, 0, 60);
                }
            }
        }

        $this->assertSame([], array_slice($bad, 0, 5), 'answers that cannot be typed by anyone');
    }

    /**
     * The model answer is the sentence with the answer set in bold, so an
     * answer that is not in it is an answer to a different question. This is
     * the check that would have caught a bracket parsed off by one.
     */
    public function test_an_answer_appears_in_the_sentence_it_completes(): void
    {
        $checked = 0;
        $wrong = [];

        foreach ($this->items() as [$item, $exercise]) {
            $model = $item['model_answer'] ?? null;
            if (! $model || $item['shape'] !== 'fill_blank') {
                continue;
            }

            foreach ($item['blanks'] ?? [] as $blank) {
                foreach ($blank['answers'] ?? [] as $answer) {
                    $checked++;
                    if (! str_contains(mb_strtolower($model), mb_strtolower(trim($answer)))) {
                        $wrong[] = "{$exercise['file']} #{$item['position']}: "
                            ."\"{$answer}\" is not in \"{$model}\"";
                    }
                }
            }
        }

        $this->assertGreaterThan(200, $checked, 'too few items carried a model answer to check against');
        // A handful of model answers give an alternative wording rather than
        // the blank itself, so this is a floor rather than an absolute.
        $this->assertLessThan(
            0.05 * $checked,
            count($wrong),
            'answers that do not appear in their own sentence: '.implode(' | ', array_slice($wrong, 0, 3)),
        );
    }

    public function test_a_choice_offers_the_answer_among_its_options(): void
    {
        $bad = [];

        foreach ($this->items() as [$item, $exercise]) {
            if ($item['shape'] === 'multiple_choice') {
                $options = array_map('mb_strtolower', $item['options'] ?? []);
                foreach ($item['answers'] ?? [] as $answer) {
                    if (! in_array(mb_strtolower($answer), $options, true)) {
                        $bad[] = "{$exercise['file']} #{$item['position']}: \"{$answer}\" is not on offer";
                    }
                }

                continue;
            }

            if ($item['shape'] !== 'choice') {
                continue;
            }

            foreach ($item['blanks'] ?? [] as $blank) {
                $options = array_map('mb_strtolower', $blank['options'] ?? []);
                if ($options === []) {
                    continue;
                }
                foreach ($blank['answers'] ?? [] as $answer) {
                    if (! in_array(mb_strtolower($answer), $options, true)) {
                        $bad[] = "{$exercise['file']} #{$item['position']}: \"{$answer}\" is not on offer";
                    }
                }
            }
        }

        $this->assertSame([], array_slice($bad, 0, 5), 'a choice item whose right answer is not offered');
    }

    /**
     * A correction drill asks whether a sentence is right, and only then to
     * put it right - so about half its items are already correct and the
     * answer is to leave them alone. That is a real item, but only if the data
     * says which kind it is: an interface that assumes every one needs editing
     * would mark a learner wrong for correctly changing nothing.
     */
    public function test_a_correction_says_whether_there_is_anything_to_correct(): void
    {
        $checked = 0;
        $unflagged = [];

        foreach ($this->items() as [$item, $exercise]) {
            if ($item['shape'] !== 'correction' || ! ($item['answer'] ?? null)) {
                continue;
            }
            $checked++;

            $identical = trim($item['stem']) === trim($item['answer']);
            if ($identical !== (bool) ($item['unchanged'] ?? false)) {
                $unflagged[] = "{$exercise['file']} #{$item['position']}";
            }
        }

        $this->assertGreaterThan(100, $checked);
        $this->assertSame(
            [],
            array_slice($unflagged, 0, 5),
            'correction items whose flag disagrees with their own two forms',
        );
    }

    /**
     * Every item is filed under a unit of the book it came from, and the disc
     * covers units 1 to 100. An item outside that range is a filename parsed
     * wrongly, and would be attached to no unit or to the wrong one.
     */
    public function test_every_exercise_belongs_to_a_unit_of_the_book(): void
    {
        foreach ($this->disc()['exercises'] as $exercise) {
            $this->assertGreaterThanOrEqual(1, $exercise['unit'], $exercise['file']);
            $this->assertLessThanOrEqual(100, $exercise['unit'], $exercise['file']);
            $this->assertNotSame('', trim($exercise['rubric']), "{$exercise['file']} has no instruction");
        }
    }

    /**
     * The disc cites its recordings by filename, and the placement tool kept
     * those names exactly so the citation still resolves. A rename would leave
     * every citation pointing at nothing, silently.
     */
    public function test_the_recordings_an_item_cites_are_where_it_says(): void
    {
        $cited = [];
        foreach ($this->items() as [$item, $_]) {
            if (! empty($item['audio'])) {
                $cited[] = basename($item['audio']);
            }
        }
        $cited = array_values(array_unique($cited));

        if ($cited === []) {
            $this->markTestSkipped('No item on the disc cites a recording.');
        }

        $directory = base_path('../sources/audio/grammar_advanced');
        if (! is_dir($directory)) {
            $this->markTestSkipped('The disc recordings are not present in this checkout.');
        }

        $missing = [];
        foreach (array_slice($cited, 0, 200) as $name) {
            if (! is_file($directory.'/'.$name)) {
                $missing[] = $name;
            }
        }

        $this->assertSame([], array_slice($missing, 0, 5), 'cited recordings that are not on disk');
    }

    /** @return list<string> */
    private function answersOf(array $item): array
    {
        if ($item['shape'] === 'correction') {
            return array_filter([$item['answer'] ?? null]);
        }
        if ($item['shape'] === 'multiple_choice') {
            return $item['answers'] ?? [];
        }

        $out = [];
        foreach ($item['blanks'] ?? [] as $blank) {
            foreach ($blank['answers'] ?? [] as $answer) {
                $out[] = $answer;
            }
        }

        return $out;
    }
}
