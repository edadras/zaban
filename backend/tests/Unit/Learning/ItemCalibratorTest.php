<?php

namespace Tests\Unit\Learning;

use App\Services\Learning\ItemCalibrator;
use PHPUnit\Framework\TestCase;

class ItemCalibratorTest extends TestCase
{
    private ItemCalibrator $calibrator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calibrator = new ItemCalibrator;
    }

    public function test_it_refuses_to_move_an_item_on_thin_evidence(): void
    {
        $this->assertNull($this->calibrator->calibrate(
            $this->attempts(abilities: array_fill(0, 5, 0.0), trueDifficulty: 2.0),
            prior: 0.0,
        ));
    }

    /**
     * An item seeded at 0.0 that a broad sample answers as though it were at
     * 1.5 should be pulled up toward 1.5 - not all the way on one pass, and
     * never past it.
     */
    public function test_it_moves_a_mis_seeded_item_toward_the_evidence(): void
    {
        $abilities = [];
        for ($i = 0; $i < 200; $i++) {
            $abilities[] = -2.0 + ($i * 0.02);
        }

        $result = $this->calibrator->calibrate(
            $this->attempts($abilities, trueDifficulty: 1.5),
            prior: 0.0,
        );

        $this->assertNotNull($result);
        $this->assertGreaterThan(0.0, $result['difficulty'], 'the item should have got harder');
        $this->assertLessThanOrEqual(1.5, $result['difficulty'], 'it should not overshoot the evidence');
        $this->assertEqualsWithDelta(1.5, $result['raw'], 0.45, 'the unshrunk estimate should find the truth');
    }

    public function test_the_prior_holds_when_the_evidence_agrees_with_it(): void
    {
        $abilities = [];
        for ($i = 0; $i < 120; $i++) {
            $abilities[] = -2.0 + ($i * 0.033);
        }

        $result = $this->calibrator->calibrate(
            $this->attempts($abilities, trueDifficulty: 0.4),
            prior: 0.4,
        );

        $this->assertNotNull($result);
        $this->assertEqualsWithDelta(0.4, $result['difficulty'], 0.25);
    }

    public function test_a_small_sample_barely_moves_the_estimate(): void
    {
        // A spread of abilities, and a true difficulty close enough to the
        // prior that neither sample runs into the per-pass cap - the cap would
        // otherwise flatten both to the same number and prove nothing.
        $spread = static function (int $n): array {
            $out = [];
            for ($i = 0; $i < $n; $i++) {
                $out[] = -1.5 + (3.0 * $i / max(1, $n - 1));
            }

            return $out;
        };

        $small = $this->calibrator->calibrate(
            $this->attempts($spread(ItemCalibrator::MIN_ATTEMPTS), 0.8),
            prior: 0.0,
        );

        $large = $this->calibrator->calibrate(
            $this->attempts($spread(400), 0.8),
            prior: 0.0,
        );

        $this->assertNotNull($small);
        $this->assertNotNull($large);
        $this->assertLessThan(
            $large['shift'],
            $small['shift'],
            'twenty answers must not count for as much as four hundred',
        );
    }

    public function test_no_single_pass_moves_an_item_further_than_the_cap(): void
    {
        $result = $this->calibrator->calibrate(
            $this->attempts(array_fill(0, 500, 0.0), trueDifficulty: 5.5),
            prior: 0.0,
        );

        $this->assertNotNull($result);
        $this->assertLessThanOrEqual(ItemCalibrator::MAX_SHIFT + 0.001, abs($result['shift']));
    }

    /**
     * The failure this bank actually shipped: an item with two correct answers.
     * It is not hard - people who know the word choose the other right answer -
     * so ability stops predicting success, and that is what has to be caught.
     */
    public function test_it_flags_an_item_that_strong_learners_fail(): void
    {
        $attempts = [];
        for ($i = 0; $i < 40; $i++) {
            // Everyone is comfortably above the item, and half of them miss it
            // anyway because a distractor is also correct.
            $attempts[] = ['ability' => 1.5, 'correct' => $i % 2 === 0];
        }

        $result = $this->calibrator->calibrate($attempts, prior: 0.0);

        $this->assertNotNull($result);
        $this->assertTrue($result['suspect'], 'an item strong learners fail at chance is not merely hard');
    }

    public function test_a_genuinely_hard_item_is_not_flagged(): void
    {
        $abilities = [];
        for ($i = 0; $i < 200; $i++) {
            $abilities[] = -1.0 + ($i * 0.02);
        }

        $result = $this->calibrator->calibrate(
            $this->attempts($abilities, trueDifficulty: 2.0),
            prior: 2.0,
        );

        $this->assertNotNull($result);
        $this->assertFalse($result['suspect'], 'hard is not the same as broken');
    }

    /**
     * Responses drawn from the model without a random number generator.
     *
     * A step function - correct exactly when ability clears difficulty - is not
     * what the model describes, and estimating against it recovers the wrong
     * number for the right reason. Instead each attempt takes the next value of
     * a golden-ratio sequence, which spreads uniformly over [0,1) and so
     * reproduces each attempt's success probability to within one attempt,
     * deterministically.
     *
     * @param  array<int, float>  $abilities
     * @return array<int, array{ability: float, correct: bool}>
     */
    private function attempts(array $abilities, float $trueDifficulty): array
    {
        $phi = 0.6180339887498949;
        $out = [];

        foreach (array_values($abilities) as $i => $ability) {
            $p = 1 / (1 + exp(-($ability - $trueDifficulty)));
            $u = fmod(0.5 + ($i + 1) * $phi, 1.0);
            $out[] = ['ability' => $ability, 'correct' => $u < $p];
        }

        return $out;
    }
}
