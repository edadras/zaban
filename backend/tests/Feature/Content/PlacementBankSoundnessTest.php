<?php

namespace Tests\Feature\Content;

use App\Services\Content\SentenceQuality;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The placement bank decides where a learner starts. An item in it that punishes
 * a correct answer is not a small defect: the first bank shipped with "I'm
 * ______, thanks." offering both "fine" and "very well", and a C1 speaker
 * answering carefully was placed at A1.
 *
 * These read the real corpus, and skip where none is imported.
 */
class PlacementBankSoundnessTest extends TestCase
{
    private function corpus(): ConnectionInterface
    {
        $connection = DB::connection('content');

        try {
            $connection->getPdo();
        } catch (\Throwable $e) {
            $this->markTestSkipped('The content database is not reachable here: '.$e->getMessage());
        }

        if ($connection->table('exercises')->where('is_placement_eligible', true)->doesntExist()) {
            $this->markTestSkipped('No placement bank has been built in this environment.');
        }

        return $connection;
    }

    public function test_every_placement_item_has_exactly_one_correct_option(): void
    {
        $broken = $this->corpus()->table('exercises')
            ->join('exercise_options', 'exercise_options.exercise_id', '=', 'exercises.id')
            ->where('exercises.is_placement_eligible', true)
            ->groupBy('exercises.id')
            ->havingRaw('SUM(exercise_options.is_correct) <> 1 OR COUNT(exercise_options.id) < 4')
            ->pluck('exercises.id');

        $this->assertSame([], $broken->all(), 'these items cannot be answered as posed');
    }

    /**
     * These books teach a synonym set one lesson at a time, so the other words
     * in the lesson are exactly the ones that also fit the gap.
     */
    public function test_no_placement_item_takes_a_wrong_answer_from_its_own_lesson(): void
    {
        $offenders = $this->corpus()->table('exercise_options')
            ->join('exercises', 'exercises.id', '=', 'exercise_options.exercise_id')
            ->join('lesson_concept', 'lesson_concept.lesson_id', '=', 'exercises.lesson_id')
            ->join('concepts', 'concepts.id', '=', 'lesson_concept.concept_id')
            ->where('exercises.is_placement_eligible', true)
            ->where('exercise_options.is_correct', false)
            ->whereColumn('concepts.label', 'exercise_options.text')
            ->distinct()
            ->limit(5)
            ->pluck('exercise_options.text', 'exercises.id');

        $this->assertSame(
            [],
            $offenders->all(),
            'a word taught beside the answer is likely to be a second correct answer',
        );
    }

    public function test_every_placement_stem_meets_the_placement_standard(): void
    {
        $quality = new SentenceQuality;

        $failing = $this->corpus()->table('exercises')
            ->where('is_placement_eligible', true)
            ->pluck('stem', 'id')
            ->reject(fn ($stem) => $quality->isPlacementGrade($stem))
            ->take(5);

        $this->assertSame([], $failing->all(), 'these stems are not clean enough to measure with');
    }

    /**
     * A bank clustered in the middle cannot tell a strong learner from a very
     * strong one. The first one held nothing at all between +1.36 and +2.68.
     */
    public function test_the_bank_covers_the_ability_scale_without_a_hole(): void
    {
        $bins = $this->corpus()->table('exercises')
            ->where('is_placement_eligible', true)
            ->whereBetween('difficulty', [-2.5, 3.0])
            ->selectRaw('FLOOR(difficulty * 2) / 2 AS bin, COUNT(*) AS n')
            ->groupBy('bin')
            ->pluck('n', 'bin');

        $missing = [];
        for ($low = -2.5; $low < 3.0; $low += 0.5) {
            if (($bins[number_format($low, 4, '.', '')] ?? 0) < 1) {
                $missing[] = sprintf('%+.1f', $low);
            }
        }

        $this->assertSame([], $missing, 'no item exists at these abilities, so nobody there can be measured');
    }

    /**
     * A dimension with no items closes on the starting prior and reports that
     * guess as a measured level. Six of the seven skills did exactly that.
     */
    public function test_a_skill_is_only_marked_assessed_when_the_bank_can_assess_it(): void
    {
        $claimed = $this->corpus()->table('skills')
            ->where('assessed_in_placement', true)
            ->pluck('code', 'id');

        $withItems = $this->corpus()->table('exercises')
            ->where('is_placement_eligible', true)
            ->distinct()
            ->pluck('skill_id')
            ->all();

        foreach ($claimed as $id => $code) {
            $this->assertContains(
                $id,
                $withItems,
                "{$code} is marked as assessed in placement but the bank holds no {$code} item",
            );
        }
    }

    public function test_no_option_is_repeated_within_an_item(): void
    {
        $dupes = $this->corpus()->table('exercise_options')
            ->join('exercises', 'exercises.id', '=', 'exercise_options.exercise_id')
            ->where('exercises.is_placement_eligible', true)
            ->selectRaw('exercises.id, LOWER(exercise_options.text) AS t, COUNT(*) AS n')
            ->groupBy('exercises.id', 't')
            ->havingRaw('COUNT(*) > 1')
            ->limit(5)
            ->pluck('t', 'id');

        $this->assertSame([], $dupes->all());
    }
}
