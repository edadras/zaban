<?php

namespace Tests\Feature\Learning;

use App\Models\Exercise;
use App\Services\Learning\DifficultyService;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The difficulty engine decides what a learner sees next. These assertions pin
 * the behaviour the product promises: items land in the learner's zone of
 * proximal development, and the ability estimate converges rather than drifting.
 */
class AdaptiveEngineTest extends TestCase
{
    use RefreshDatabase;

    private DifficultyService $difficulty;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        $this->difficulty = app(DifficultyService::class);
    }

    public function test_success_probability_follows_the_logistic_curve(): void
    {
        // Ability equal to difficulty is a coin flip.
        $this->assertEqualsWithDelta(0.5, $this->difficulty->successProbability(0.0, 0.0), 0.001);

        // Well above the item, near-certain; well below, near-zero.
        $this->assertGreaterThan(0.95, $this->difficulty->successProbability(3.0, 0.0));
        $this->assertLessThan(0.05, $this->difficulty->successProbability(-3.0, 0.0));

        // Guessing raises the floor for a multiple-choice item.
        $this->assertGreaterThanOrEqual(
            0.25,
            $this->difficulty->successProbability(-5.0, 0.0, 1.0, 0.25),
        );
    }

    public function test_target_difficulty_lands_inside_the_intended_band(): void
    {
        foreach ([-2.0, -0.5, 0.0, 1.5, 3.0] as $ability) {
            $difficulty = $this->difficulty->difficultyForTarget($ability);
            $p = $this->difficulty->successProbability($ability, $difficulty);

            $this->assertGreaterThanOrEqual(DifficultyService::TARGET_MIN - 0.02, $p);
            $this->assertLessThanOrEqual(DifficultyService::TARGET_MAX + 0.02, $p);
        }
    }

    public function test_selection_prefers_items_in_the_zone_of_proximal_development(): void
    {
        $candidates = collect([
            $this->makeExercise(-4.0),   // trivial
            $this->makeExercise(-1.2),   // about right for ability 0
            $this->makeExercise(4.0),    // impossible
        ]);

        // Disable the deliberate-stretch branch so the choice is deterministic.
        $chosen = $this->difficulty->choose($candidates, 0.0, allowChallenge: false);

        $this->assertNotNull($chosen);
        $this->assertEqualsWithDelta(-1.2, (float) $chosen->difficulty, 0.001,
            'selection should pick the item closest to the target success band');
    }

    public function test_ability_estimate_converges_toward_the_true_value(): void
    {
        // Simulate a learner whose true ability is +1.0 and check the estimate
        // walks toward it rather than oscillating or running away.
        $trueAbility = 1.0;
        $ability = 0.0;
        $se = 1.5;
        mt_srand(20260905);

        for ($i = 0; $i < 60; $i++) {
            $item = $this->makeExercise($this->difficulty->difficultyForTarget($ability));
            $p = $this->difficulty->successProbability($trueAbility, (float) $item->difficulty);
            $correct = (mt_rand() / mt_getrandmax()) < $p;
            [$ability, $se] = $this->difficulty->updateAbility($ability, $se, $item, $correct);
        }

        $this->assertEqualsWithDelta($trueAbility, $ability, 1.0,
            "estimate {$ability} did not converge toward {$trueAbility}");
        $this->assertLessThan(1.5, $se, 'uncertainty should shrink as evidence accumulates');
    }

    public function test_uncertainty_never_collapses_to_false_certainty(): void
    {
        $ability = 0.0;
        $se = 1.5;
        for ($i = 0; $i < 200; $i++) {
            $item = $this->makeExercise(0.0);
            [$ability, $se] = $this->difficulty->updateAbility($ability, $se, $item, true);
        }

        $this->assertGreaterThanOrEqual(0.18, $se,
            'the standard error must keep a floor - no finite number of items proves certainty');
    }

    public function test_information_peaks_where_the_item_matches_the_learner(): void
    {
        $matched = $this->makeExercise(0.0);
        $mismatched = $this->makeExercise(4.0);

        $this->assertGreaterThan(
            $this->difficulty->information(0.0, $mismatched),
            $this->difficulty->information(0.0, $matched),
            'an item at the learner\'s level must be more informative than one far from it',
        );
    }

    private function makeExercise(float $difficulty): Exercise
    {
        $template = \App\Models\ExerciseTemplate::where('code', 'multiple_choice')->firstOrFail();

        return Exercise::create([
            'exercise_template_id' => $template->id,
            'language_id' => \App\Models\Language::where('code', 'en')->value('id'),
            'skill_id' => \App\Models\Skill::where('code', 'vocabulary')->value('id'),
            'cefr_level_id' => \App\Models\CefrLevel::where('code', 'B1')->value('id'),
            'stem' => 'test item',
            'difficulty' => $difficulty,
            'discrimination' => 1.0,
            'guessing' => 0.0,
            'status' => 'published',
            'generation_method' => 'authored',
            'copyright_status' => 'owned',
        ]);
    }
}
