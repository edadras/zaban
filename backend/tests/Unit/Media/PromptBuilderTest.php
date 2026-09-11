<?php

namespace Tests\Unit\Media;

use App\Models\Lesson;
use App\Models\Unit;
use App\Services\Media\PromptBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A dropped negative prompt is invisible: the request succeeds and the artwork
 * comes back with a watermark or baked-in text in it, which is exactly what the
 * exclusions exist to prevent. No image model in the current catalogue accepts a
 * negative-prompt argument, so these tests pin the folding that replaces it.
 */
class PromptBuilderTest extends TestCase
{
    private PromptBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = new PromptBuilder;
    }

    public function test_it_folds_exclusions_into_the_prompt_for_models_without_the_parameter(): void
    {
        config(['ai.providers.higgsfield.negative_prompt_models' => []]);

        $spec = $this->builder->forModel(
            $this->builder->vocabularyImage('luggage', 'the bags a traveller carries', 'A2'),
            'nano_banana_2',
        );

        $this->assertNull($spec['negative'], 'the negative must not be handed to a model that ignores it');
        $this->assertTrue($spec['negative_folded']);
        $this->assertStringContainsString('Do not include any of the following:', $spec['prompt']);
        $this->assertStringContainsString('watermark', $spec['prompt']);
    }

    public function test_it_leaves_the_negative_alone_for_a_model_that_declares_support(): void
    {
        config(['ai.providers.higgsfield.negative_prompt_models' => ['some_future_model']]);

        $spec = $this->builder->forModel(
            $this->builder->vocabularyImage('luggage', null, 'A2'),
            'some_future_model',
        );

        $this->assertNotNull($spec['negative']);
        $this->assertArrayNotHasKey('negative_folded', $spec);
        $this->assertStringNotContainsString('Do not include any of the following:', $spec['prompt']);
    }

    public function test_folding_is_idempotent_and_does_not_stack(): void
    {
        config(['ai.providers.higgsfield.negative_prompt_models' => []]);

        $once = $this->builder->forModel($this->builder->vocabularyImage('key', null), 'nano_banana_2');
        $twice = $this->builder->forModel($once, 'nano_banana_2');

        $this->assertSame($once['prompt'], $twice['prompt']);
        $this->assertSame(1, substr_count($twice['prompt'], 'Do not include any of the following:'));
    }

    public function test_level_drives_visual_complexity(): void
    {
        $beginner = $this->builder->vocabularyImage('cat', null, 'A1')['prompt'];
        $advanced = $this->builder->vocabularyImage('cat', null, 'C1')['prompt'];

        $this->assertStringContainsString('one obvious subject', $beginner);
        $this->assertStringContainsString('nuanced', $advanced);
        $this->assertNotSame($beginner, $advanced);
    }

    /**
     * Roughly three quarters of the extracted "examples" are fragments the PDF
     * parser caught mid-clause. Each one that slips through becomes an image of
     * the fragment, so these are the exact strings the gate exists to stop.
     */
    #[DataProvider('fragmentProvider')]
    public function test_it_rejects_extraction_fragments(string $fragment): void
    {
        $this->assertFalse(
            PromptBuilder::isUsableExample($fragment),
            "should have rejected: {$fragment}",
        );
    }

    public static function fragmentProvider(): array
    {
        return [
            'starts mid-clause' => ['approve of my choice of profession and support me fully.'],
            'unfinished' => ['Air travel is'],
            'trailing preposition' => ['enormous impact on'],
            'typographic debris' => ['or wound up / stressed out**'],
            'lexis note' => ['in a fix / in a spot / in a hole / up against it'],
            'has e.g.' => ['Averse to means opposed to, usually used with not, e.g. I am not.'],
            'dictionary prose' => ['If you broadcast something, you send it out on TV or radio.'],
            'metalinguistic' => ['Yearn for is a more poetic way of saying long for.'],
            'too short' => ['Take off.'],
            'empty' => [''],
            'null-ish' => ['   '],
        ];
    }

    #[DataProvider('sentenceProvider')]
    public function test_it_accepts_real_sentences(string $sentence): void
    {
        $this->assertTrue(
            PromptBuilder::isUsableExample($sentence),
            "should have accepted: {$sentence}",
        );
    }

    public static function sentenceProvider(): array
    {
        return [
            ['Do you take sugar in tea or coffee?'],
            ['Can I have the bill, please?'],
            ['Take off your coat and sit down.'],
            ['People sometimes cry if they are very unhappy.'],
            ['We had a couple of heavy showers this morning.'],
        ];
    }

    public function test_word_class_changes_the_framing(): void
    {
        $noun = $this->builder->vocabularyImage('suitcase', null, 'A2', 'noun')['prompt'];
        $adjective = $this->builder->vocabularyImage('delighted', null, 'A2', 'adjective')['prompt'];

        $this->assertStringContainsString('product-photography', $noun);
        $this->assertStringContainsString('One unambiguous subject', $noun);

        // An adjective has no object to photograph; it needs a person.
        $this->assertStringContainsString('a person or a moment', $adjective);
        $this->assertStringNotContainsString('product-photography', $adjective);
    }

    public function test_context_sentence_is_quoted_into_the_prompt_verbatim(): void
    {
        $spec = $this->builder->vocabularyImage('bill', '"Can I have the bill, please?"', 'A2', 'noun');

        $this->assertStringContainsString('It is used like this: "Can I have the bill, please?"', $spec['prompt']);
        $this->assertStringNotContainsString('Meaning: Used in context like', $spec['prompt']);
    }

    public function test_a_scene_asks_for_one_frame_rather_than_a_set_of_panels(): void
    {
        /*
         * Measured, not assumed: asked to make six ideas obvious, the scene
         * model returned a five-panel contact sheet both times it was tried.
         * At the size a lesson card draws it, every panel is too small to read
         * and the picture is no longer a situation anyone can talk about - so
         * the single-frame instruction has to lead, not merely be implied.
         */
        $lesson = new Lesson(['title' => 'Family words']);
        $prompt = $this->builder->lessonScene($lesson, ['husband', 'wife', 'children'])['prompt'];

        $this->assertStringContainsString('One single photograph', $prompt);
        $this->assertStringContainsString('not a collage', $prompt);

        // The phrasing that invited the panels in the first place.
        $this->assertStringNotContainsString('clearly separated elements', $prompt);
    }

    public function test_a_verb_paradigm_lesson_is_asked_for_a_moment_not_its_words(): void
    {
        /*
         * The irregular-verb units carry target words straight out of the book,
         * and they are function-word fragments: "got, It's got, ve got, haven't
         * got". Handed to an image model those produce a confidently
         * meaningless picture - there is nothing there to photograph.
         */
        $unit = new Unit(['title' => 'Have / had / had']);
        $lesson = new Lesson(['title' => 'What can you have?']);
        $lesson->setRelation('unit', $unit);

        $prompt = $this->builder->lessonScene($lesson, ['got', "It's got", "haven't got"])['prompt'];

        $this->assertStringContainsString('naturally use the verb "Have"', $prompt);
        $this->assertStringNotContainsString("haven't got", $prompt);
    }

    public function test_an_ordinary_lesson_still_gets_its_target_words(): void
    {
        // The rule is narrow on purpose. "Adjectives describing appearance" and
        // "clothes and fashion" read as grammar to a coarser filter and are
        // both perfectly photographable.
        $unit = new Unit(['title' => 'Clothes']);
        $lesson = new Lesson(['title' => 'Words and expressions about clothes']);
        $lesson->setRelation('unit', $unit);

        $prompt = $this->builder->lessonScene($lesson, ['jacket', 'scarf', 'gloves'])['prompt'];

        $this->assertStringContainsString('jacket, scarf, gloves', $prompt);
        $this->assertStringNotContainsString('naturally use the verb', $prompt);
    }

    #[DataProvider('unphotographableWordLists')]
    public function test_a_word_list_with_nothing_to_photograph_asks_for_a_moment(array $words): void
    {
        $lesson = new Lesson(['title' => 'Basic conjunctions']);
        $lesson->setRelation('unit', new Unit(['title' => 'Conjunctions and connecting words']));

        $prompt = $this->builder->lessonScene($lesson, $words)['prompt'];

        // A picture of "example, use, and, but, or" is a picture of nothing,
        // and the model returns something confidently meaningless.
        $this->assertStringContainsString('ordinary everyday situation', $prompt);
        $this->assertStringNotContainsString('should be obvious within that one scene', $prompt);
    }

    public static function unphotographableWordLists(): array
    {
        return [
            'grammar labels' => [['example', 'use', 'and', 'but', 'or', 'because']],
            'contractions the extractor split' => [["It's got", 've got', "haven't got"]],
            'a cross-reference caught mid-word' => [['Unit 32: T', 'ravelling']],
            'closed-class words' => [['now', 'then', 'here', 'there']],
        ];
    }

    #[DataProvider('photographableWordLists')]
    public function test_a_word_list_with_something_in_it_is_kept(array $words, string $expected): void
    {
        $lesson = new Lesson(['title' => 'Things in the kitchen']);
        $lesson->setRelation('unit', new Unit(['title' => 'In the kitchen']));

        $prompt = $this->builder->lessonScene($lesson, $words)['prompt'];

        // The filter has to stay conservative: a coarser one throws away
        // "adjectives describing appearance" and "expressions about clothes",
        // and both of those photograph perfectly well.
        $this->assertStringContainsString($expected, $prompt);
    }

    public static function photographableWordLists(): array
    {
        return [
            'plain nouns' => [['cupboard', 'fridge', 'microwave'], 'cupboard, fridge, microwave'],
            'a phrase with one real word' => [['ask someone the way', 'go swimming'], 'ask someone the way'],
            'labels mixed with things' => [['example', 'use', 'carrots', 'beans'], 'carrots, beans'],
        ];
    }

    public function test_a_scene_is_asked_for_in_the_shape_the_client_draws_it(): void
    {
        // The one place that displays this artwork puts it in a 4:3 box. A 16:9
        // scene is cropped there, off-centre, after being paid for in full.
        $this->assertSame('4:3', $this->builder->lessonScene(new Lesson(['title' => 'Anything']))['aspect_ratio']);
    }

    public function test_a_montage_is_excluded_from_every_kind_of_artwork(): void
    {
        // A vocabulary card and a scene are drawn at the same size by the same
        // client, so a grid is exactly as unusable on one as on the other.
        foreach ([
            $this->builder->lessonScene(new Lesson(['title' => 'Family words'])),
            $this->builder->vocabularyImage('suitcase', null, 'A2', 'noun'),
        ] as $spec) {
            $this->assertStringContainsString('collage', (string) $spec['negative']);
            $this->assertStringContainsString('contact sheet', (string) $spec['negative']);
        }
    }
}
