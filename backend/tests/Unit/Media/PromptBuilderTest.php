<?php

namespace Tests\Unit\Media;

use App\Models\Lesson;
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
