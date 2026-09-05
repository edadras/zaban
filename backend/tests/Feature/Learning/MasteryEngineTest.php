<?php

namespace Tests\Feature\Learning;

use App\Models\CefrLevel;
use App\Models\Concept;
use App\Models\Language;
use App\Models\Skill;
use App\Models\User;
use App\Models\VocabularyItem;
use App\Models\VocabularySense;
use App\Services\Learning\MasteryService;
use App\Services\Learning\SpacedRepetitionService;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The learning state machine. These assertions encode the rules the product
 * depends on: mastery must mean durable retrieval, and it must not be reachable
 * by answering the same thing repeatedly in one sitting.
 */
class MasteryEngineTest extends TestCase
{
    use RefreshDatabase;

    private MasteryService $mastery;
    private SpacedRepetitionService $srs;
    private User $user;
    private Concept $concept;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        $this->mastery = app(MasteryService::class);
        $this->srs = app(SpacedRepetitionService::class);
        $this->user = User::factory()->create();
        $this->concept = $this->makeConcept('kettle');
    }

    public function test_one_correct_answer_does_not_confer_mastery(): void
    {
        $state = $this->mastery->record($this->user->id, $this->concept->id, correct: true);

        $this->assertLessThanOrEqual(MasteryService::INTRODUCED, (float) $state->mastery_score,
            'a single correct answer must not exceed the introduced band');
        $this->assertNull($state->mastered_at);
    }

    public function test_cramming_cannot_manufacture_mastery(): void
    {
        // Ten correct answers in a row, all in the same sitting.
        for ($i = 0; $i < 10; $i++) {
            $state = $this->mastery->record($this->user->id, $this->concept->id, correct: true);
        }

        $this->assertLessThan(MasteryService::COMPETENT, (float) $state->mastery_score,
            'unspaced repetition should not push a concept past developing');
        $this->assertNull($state->mastered_at);
    }

    public function test_mastery_is_reached_through_spaced_successful_retrieval(): void
    {
        $state = null;
        // Four successes, each on a different day.
        for ($day = 0; $day < 5; $day++) {
            Carbon::setTestNow(now()->addDays($day * 2));
            $state = $this->mastery->record($this->user->id, $this->concept->id, correct: true);
        }
        Carbon::setTestNow();

        $this->assertGreaterThanOrEqual(MasteryService::STRONG, (float) $state->mastery_score,
            'spaced successful retrieval should reach at least the strong band');
    }

    public function test_a_failure_drops_mastery_but_not_to_zero(): void
    {
        for ($day = 0; $day < 4; $day++) {
            Carbon::setTestNow(now()->addDays($day * 2));
            $this->mastery->record($this->user->id, $this->concept->id, correct: true);
        }
        Carbon::setTestNow(now()->addDays(10));
        $before = $this->mastery->stateFor($this->user->id, $this->concept->id)->mastery_score;
        $after = $this->mastery->record($this->user->id, $this->concept->id, correct: false);
        Carbon::setTestNow();

        $this->assertLessThan((float) $before, (float) $after->mastery_score);
        $this->assertGreaterThanOrEqual(MasteryService::INTRODUCED, (float) $after->mastery_score,
            'a lapse should not erase the fact the learner has met the concept');
        $this->assertSame(0, (int) $after->consecutive_correct);
    }

    public function test_hints_reduce_the_credit_earned(): void
    {
        $withHelp = $this->mastery->record($this->user->id, $this->concept->id, correct: true, hintsUsed: 3);

        $other = $this->makeConcept('wardrobe');
        $unaided = $this->mastery->record($this->user->id, $other->id, correct: true);

        $this->assertLessThan((float) $unaided->mastery_score, (float) $withHelp->mastery_score,
            'an answer reached with hints should be worth less than an unaided one');
    }

    public function test_a_lapse_shortens_the_review_interval_and_reduces_ease(): void
    {
        for ($day = 0; $day < 4; $day++) {
            Carbon::setTestNow(now()->addDays($day * 3));
            $this->mastery->record($this->user->id, $this->concept->id, correct: true);
        }
        $grown = $this->mastery->stateFor($this->user->id, $this->concept->id);
        $intervalBefore = (int) $grown->interval_days;
        $easeBefore = (float) $grown->ease_factor;

        Carbon::setTestNow(now()->addDays(20));
        $lapsed = $this->mastery->record($this->user->id, $this->concept->id, correct: false);
        Carbon::setTestNow();

        $this->assertLessThan($intervalBefore, (int) $lapsed->interval_days);
        $this->assertLessThan($easeBefore, (float) $lapsed->ease_factor,
            'repeated lapses must compound, so ease has to fall');
    }

    public function test_forgetting_probability_rises_with_elapsed_time(): void
    {
        $state = $this->mastery->record($this->user->id, $this->concept->id, correct: true);

        $soon = $this->srs->forgettingProbability($state, now()->addHours(2));
        $later = $this->srs->forgettingProbability($state, now()->addDays(30));

        $this->assertLessThan($later, $soon,
            'the forgetting curve must increase with time since the last encounter');
        $this->assertGreaterThanOrEqual(0.0, $soon);
        $this->assertLessThanOrEqual(1.0, $later);
    }

    public function test_confidence_is_lower_for_mixed_results_than_consistent_ones(): void
    {
        // Consistent learner.
        $steady = $this->makeConcept('cushion');
        for ($i = 0; $i < 6; $i++) {
            Carbon::setTestNow(now()->addDays($i * 2));
            $steadyState = $this->mastery->record($this->user->id, $steady->id, correct: true);
        }

        // Coin-flip learner, same number of encounters.
        $mixed = $this->makeConcept('saucepan');
        for ($i = 0; $i < 6; $i++) {
            Carbon::setTestNow(now()->addDays($i * 2));
            $mixedState = $this->mastery->record($this->user->id, $mixed->id, correct: $i % 2 === 0);
        }
        Carbon::setTestNow();

        $this->assertGreaterThan((float) $mixedState->confidence, (float) $steadyState->confidence,
            'mixed results should produce a less confident estimate than consistent ones');
    }

    public function test_due_queue_only_returns_reviews_that_are_actually_due(): void
    {
        $this->mastery->record($this->user->id, $this->concept->id, correct: true);

        $this->assertSame(0, $this->srs->dueCount($this->user->id),
            'a review scheduled for the future must not be due now');

        Carbon::setTestNow(now()->addDays(3));
        $this->assertGreaterThan(0, $this->srs->dueCount($this->user->id));
        Carbon::setTestNow();
    }

    private function makeConcept(string $label): Concept
    {
        $en = Language::where('code', 'en')->firstOrFail();
        $a2 = CefrLevel::where('code', 'A2')->firstOrFail();
        $vocab = Skill::where('code', 'vocabulary')->firstOrFail();

        $item = VocabularyItem::create([
            'language_id' => $en->id, 'headword' => $label, 'normalised' => $label,
            'cefr_level_id' => $a2->id,
        ]);
        $sense = VocabularySense::create([
            'vocabulary_item_id' => $item->id, 'sense_number' => 1, 'cefr_level_id' => $a2->id,
        ]);

        return Concept::create([
            'conceptable_type' => VocabularySense::class, 'conceptable_id' => $sense->id,
            'language_id' => $en->id, 'skill_id' => $vocab->id, 'cefr_level_id' => $a2->id,
            'label' => $label, 'difficulty' => -1.0, 'importance' => 0.5, 'is_active' => true,
        ]);
    }
}
