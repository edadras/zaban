<?php

namespace Tests\Unit\Media;

use App\Services\Media\VideoTreatment;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Video quota is finite and a clip costs far more than a still, so what this
 * class decides is what actually gets made. The cases below are drawn from real
 * lesson titles in the corpus.
 */
class VideoTreatmentTest extends TestCase
{
    private VideoTreatment $treatment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->treatment = new VideoTreatment;
    }

    /**
     * There is no footage of a suffix. Filming these spends real quota on clips
     * that cannot teach anything the still does not teach better.
     */
    #[DataProvider('metalinguisticProvider')]
    public function test_lessons_about_language_itself_get_no_video(string $title): void
    {
        $this->assertNull($this->treatment->forLesson($title), "should have skipped: {$title}");
    }

    public static function metalinguisticProvider(): array
    {
        return [
            ['Prefixes: creating new meanings'],
            ['Suffixes: forming new words'],
            ['Word-building and word-blending'],
            ['Abbreviations and acronyms'],
            ['Adverbs and adjectives'],
            ['Easily confused words'],
            ['Style and register'],
            ['Connecting and linking words'],
            ['Saying \u{2018}0\u{2019}'],
        ];
    }

    #[DataProvider('tierProvider')]
    public function test_it_ranks_lessons_by_how_much_motion_adds(string $title, string $expected): void
    {
        $this->assertSame($expected, $this->treatment->forLesson($title)['tier']);
    }

    public static function tierProvider(): array
    {
        return [
            'a situation' => ['Asking questions about hotel services', VideoTreatment::TIER_SCENARIO],
            'also a situation' => ['At the doctor', VideoTreatment::TIER_SCENARIO],
            'things that happen' => ['Exercise and calories', VideoTreatment::TIER_ACTION],
            'also things that happen' => ['Weather conversations', VideoTreatment::TIER_SCENARIO],
            'things that simply are' => ['Bathroom', VideoTreatment::TIER_AMBIENT],
        ];
    }

    public function test_the_render_order_puts_the_strongest_clips_first(): void
    {
        $dialogue = $this->treatment->priorityFor(VideoTreatment::TIER_DIALOGUE);
        $scenario = $this->treatment->priorityFor(VideoTreatment::TIER_SCENARIO);
        $action = $this->treatment->priorityFor(VideoTreatment::TIER_ACTION);
        $ambient = $this->treatment->priorityFor(VideoTreatment::TIER_AMBIENT);

        $this->assertLessThan($scenario, $dialogue);
        $this->assertLessThan($action, $scenario);
        $this->assertLessThan($ambient, $action);
    }

    public function test_a_still_life_lesson_gets_the_gentlest_motion(): void
    {
        // The still is already doing the teaching here; the clip only stops it
        // feeling dead. Anything more and the scene stops holding its words.
        $ambient = $this->treatment->forLesson('Bathroom');

        $this->assertStringContainsString('Almost nothing moves', $ambient['motion']);
    }

    public function test_it_infers_a_place_a_camera_could_stand_in(): void
    {
        $this->assertSame('a small hotel reception', $this->treatment->settingFor('In a hotel'));
        $this->assertSame('a bright classroom', $this->treatment->settingFor('School and study'));
        $this->assertSame('a small clinic consulting room', $this->treatment->settingFor('Health and illness'));
    }

    public function test_functional_language_falls_back_to_a_concrete_neutral_place(): void
    {
        // "Expressing regret" has no setting of its own, but the fallback must
        // still be somewhere a camera can point - not "an everyday setting".
        $setting = $this->treatment->settingFor('Reminiscences and regrets Expressing regret', 3);

        $this->assertNotSame('', $setting);
        $this->assertStringNotContainsString('everyday setting', $setting);
    }

    public function test_the_fallback_varies_by_seed_but_never_moves_for_the_same_one(): void
    {
        // Two thirds of dialogues land on the fallback, so one fixed place would
        // put the same room behind fifty clips. It must still be stable, or a
        // rebuild would reshuffle scenes that have already been rendered.
        $settings = array_map(fn ($i) => $this->treatment->settingFor('Expressions', $i), range(0, 6));

        $this->assertGreaterThan(3, count(array_unique($settings)));
        $this->assertSame(
            $this->treatment->settingFor('Expressions', 3),
            $this->treatment->settingFor('Expressions', 3),
        );
    }
}
