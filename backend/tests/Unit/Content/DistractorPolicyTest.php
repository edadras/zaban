<?php

namespace Tests\Unit\Content;

use App\Services\Content\DistractorPolicy;
use PHPUnit\Framework\TestCase;

/**
 * The pairs below all shipped together in one item, and each one made the item
 * unanswerable: both options were correct English in the same blank.
 */
class DistractorPolicyTest extends TestCase
{
    private DistractorPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new DistractorPolicy;
    }

    public function test_synonyms_are_not_provably_distinct(): void
    {
        // "I'm ______, thanks." shipped with both of these as options.
        $this->assertFalse($this->policy->provablyDistinct(
            'in good health and feeling well',
            'in good health, feeling well and happy',
        ));
    }

    public function test_morphological_variants_are_not_provably_distinct(): void
    {
        // "yell" and "yelled" both appeared as options on the same item.
        $this->assertTrue($this->policy->overlapsTerm('yell', 'yelled'));
        $this->assertTrue($this->policy->overlapsTerm('longing for', 'longing'));
    }

    public function test_a_shorter_phrase_inside_a_longer_one_is_rejected(): void
    {
        $this->assertTrue($this->policy->overlapsTerm('very well', 'well'));
        $this->assertTrue($this->policy->overlapsTerm('flute-player', 'player'));
    }

    public function test_genuinely_different_words_are_distinct(): void
    {
        $this->assertFalse($this->policy->overlapsTerm('witness', 'jury'));
        $this->assertTrue($this->policy->provablyDistinct(
            'a person who sees a crime being committed',
            'the highest point of a mountain',
        ));
    }

    public function test_it_refuses_to_build_an_item_it_cannot_justify(): void
    {
        // Undefined siblings from the same module: nothing proves they are wrong.
        $result = $this->policy->choose('fine', null, "I'm ______, thanks.", [
            ['term' => 'very well', 'definition' => null, 'module_id' => 1],
            ['term' => 'feel ill', 'definition' => null, 'module_id' => 1],
            ['term' => 'not bad', 'definition' => null, 'module_id' => 1],
        ], 3, 1);

        $this->assertNull($result);
    }

    public function test_defined_and_separate_options_yield_a_proven_item(): void
    {
        $result = $this->policy->choose(
            'peak',
            'the highest point of a mountain',
            'They reached the ______ just after dawn.',
            [
                ['term' => 'kettle', 'definition' => 'a container for boiling water', 'module_id' => 2],
                ['term' => 'invoice', 'definition' => 'a bill sent to a customer', 'module_id' => 3],
                ['term' => 'rehearsal', 'definition' => 'a practice session before a performance', 'module_id' => 4],
                ['term' => 'summit', 'definition' => 'the highest point of a mountain', 'module_id' => 1],
            ],
            3,
            1,
        );

        $this->assertNotNull($result);
        $this->assertSame(DistractorPolicy::PROVEN, $result['grade']);
        $this->assertNotContains('summit', $result['options'], 'summit means the same as peak');
        $this->assertCount(3, $result['options']);
    }

    public function test_a_word_already_printed_in_the_stem_is_never_an_option(): void
    {
        $result = $this->policy->choose(
            'income tax',
            'money paid to the government from earnings',
            "Compound nouns like haircut and ______ are stressed on the first part.",
            [
                ['term' => 'haircut', 'definition' => 'an act of cutting hair', 'module_id' => 2],
                ['term' => 'kettle', 'definition' => 'a container for boiling water', 'module_id' => 2],
                ['term' => 'invoice', 'definition' => 'a bill sent to a customer', 'module_id' => 3],
                ['term' => 'rehearsal', 'definition' => 'a practice session', 'module_id' => 4],
            ],
            3,
            1,
        );

        $this->assertNotNull($result);
        $this->assertNotContains('haircut', $result['options']);
    }

    public function test_cross_module_options_grade_as_plausible_not_proven(): void
    {
        $result = $this->policy->choose('gadget', null, 'She bought a small ______ for the kitchen.', [
            ['term' => 'rehearsal', 'definition' => null, 'module_id' => 4],
            ['term' => 'invoice', 'definition' => null, 'module_id' => 3],
            ['term' => 'blizzard', 'definition' => null, 'module_id' => 5],
        ], 3, 1);

        $this->assertNotNull($result);
        $this->assertSame(DistractorPolicy::PLAUSIBLE, $result['grade']);
    }
}
