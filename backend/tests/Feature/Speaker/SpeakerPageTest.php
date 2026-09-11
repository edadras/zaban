<?php

namespace Tests\Feature\Speaker;

use App\Models\CefrLevel;
use App\Models\Course;
use App\Models\CourseVersion;
use App\Models\Language;
use App\Models\Lesson;
use App\Models\LessonBlock;
use App\Models\MediaAsset;
use App\Models\Module;
use App\Models\Unit;
use App\Support\SpeakerLink;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * The talking figure, and who is allowed to see one.
 *
 * The page holds no credential, so what stands between it and the world is the
 * signature on its link and the narrowness of what a link can say: one figure
 * from a fixed cast, one audio asset, for a few hours. These tests are mostly
 * about that narrowness - an id in a query string is exactly the kind of thing
 * somebody eventually tries incrementing.
 */
class SpeakerPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Levels, languages and the rest of the fixed vocabulary a lesson
        // needs to exist at all.
        $this->seed(ReferenceDataSeeder::class);
    }

    private function audio(array $attributes = []): MediaAsset
    {
        return MediaAsset::create([
            'disk' => 'local',
            'path' => 'sources/audio/scenes/clinic-sore-throat/01-doctor.mp3',
            'type' => 'audio',
            'mime' => 'audio/mpeg',
            'origin' => 'ingested',
            'copyright_status' => 'owned',
            ...$attributes,
        ]);
    }

    private function block(string $type, array $config): LessonBlock
    {
        return LessonBlock::create([
            'lesson_id' => $this->lesson()->id,
            'type' => $type,
            'position' => 1,
            'config' => $config,
            'estimated_seconds' => 60,
        ]);
    }

    /** A lesson for a block to hang from; nothing here is about its content. */
    private function lesson(): Lesson
    {
        $level = CefrLevel::where('code', 'B1')->firstOrFail();

        $course = Course::create([
            'language_id' => Language::where('code', 'en')->value('id'),
            'title' => 'Speaker course',
            'slug' => 'speaker-'.uniqid(),
            'from_cefr_level_id' => $level->id,
            'to_cefr_level_id' => $level->id,
            'is_active' => true,
        ]);
        $version = CourseVersion::create([
            'course_id' => $course->id, 'version' => 1,
            'status' => 'published', 'published_at' => now(),
        ]);
        $module = Module::create([
            'course_version_id' => $version->id, 'title' => 'Speaking', 'position' => 0,
        ]);
        $unit = Unit::create(['module_id' => $module->id, 'title' => 'Speaking', 'position' => 1]);

        return Lesson::create([
            'unit_id' => $unit->id, 'title' => 'Repeat after the speaker',
            'position' => 1, 'status' => 'published', 'difficulty' => 0.0,
        ]);
    }

    // ------------------------------------------------------------- the link

    public function test_an_unknown_character_falls_back_to_the_presenter(): void
    {
        // A wrong face is obvious and gets reported; an empty stage is not.
        $this->assertSame(config('speaker.presenter'), SpeakerLink::character('nobody'));
        $this->assertSame(config('speaker.presenter'), SpeakerLink::character(null));
    }

    public function test_a_known_character_is_kept(): void
    {
        $this->assertSame('omar', SpeakerLink::character('omar'));
        $this->assertSame('omar', SpeakerLink::character('  OMAR '));
    }

    public function test_a_link_names_the_figure_and_the_recording(): void
    {
        $audio = $this->audio();
        $link = SpeakerLink::presenter($audio, 'I have had a sore throat since Monday.');

        $this->assertStringContainsString('/speaker/'.config('speaker.presenter'), $link['url']);
        $this->assertStringContainsString('audio='.$audio->id, $link['url']);
        $this->assertGreaterThan(0, $link['expires_in']);
    }

    public function test_a_caption_is_trimmed_rather_than_carried_whole(): void
    {
        $link = SpeakerLink::presenter($this->audio(), str_repeat('a', 900));

        // A signed URL is not the place to carry a paragraph, and an over-long
        // one is refused by proxies long before it reaches the page.
        $this->assertLessThan(900, mb_strlen(urldecode($link['url'])));
    }

    // ------------------------------------------------------------- the page

    public function test_the_page_opens_with_a_valid_signature(): void
    {
        $link = SpeakerLink::presenter($this->audio(), 'Say this line.');

        $this->get($link['url'])
            ->assertOk()
            ->assertSee('Say this line.', false)
            ->assertSee('speaker-stage', false);
    }

    public function test_an_unsigned_link_is_refused(): void
    {
        $this->get('/speaker/grace?audio=1&caption=hello')->assertForbidden();
    }

    public function test_a_tampered_caption_is_refused(): void
    {
        $link = SpeakerLink::presenter($this->audio(), 'Say this line.');

        // The caption is inside the signature, so it cannot be rewritten into
        // something the product never said.
        $this->get(str_replace('Say%20this%20line.', 'Send%20money', $link['url']))
            ->assertForbidden();
    }

    public function test_an_expired_link_stops_working(): void
    {
        $url = URL::temporarySignedRoute('speaker.show', now()->addMinutes(5), [
            'character' => 'grace',
        ]);

        $this->travel(10)->minutes();

        $this->get($url)->assertForbidden();
    }

    public function test_the_page_carries_no_credential(): void
    {
        $link = SpeakerLink::presenter($this->audio(), 'Say this line.');
        $html = $this->get($link['url'])->getContent();

        $this->assertStringNotContainsString('Bearer', $html);
        $this->assertStringNotContainsString('api_token', $html);
        $this->assertStringNotContainsString('csrf', $html);
    }

    public function test_only_an_audio_asset_is_ever_served(): void
    {
        $image = $this->audio([
            'type' => 'image',
            'mime' => 'image/png',
            'path' => 'sources/boards/clinic.png',
        ]);

        $url = URL::temporarySignedRoute('speaker.show', now()->addHour(), [
            'character' => 'grace',
            'audio' => $image->id,
        ]);

        // Otherwise the figure is a way to get a signed link to anything in the
        // library by trying ids until one works.
        $this->get($url)->assertOk()->assertDontSee($image->path, false);
    }

    public function test_an_audio_id_that_does_not_exist_still_renders(): void
    {
        $url = URL::temporarySignedRoute('speaker.show', now()->addHour(), [
            'character' => 'grace',
            'audio' => 999999,
        ]);

        // The figure appears and says nothing, which the page explains. A 500
        // here would take a lesson down over a missing recording.
        $this->get($url)->assertOk()->assertSee('speaker-stage', false);
    }

    // ------------------------------------------------------- blocks and lessons

    public function test_a_block_that_asks_for_a_line_gets_a_speaker(): void
    {
        $audio = $this->audio();
        $block = $this->block('repeat_after_speaker', [
            'audio_media_asset_id' => $audio->id,
            'targets' => ['I have had a sore throat since Monday.'],
            'audio_start_ms' => 1200,
            'audio_end_ms' => 4300,
        ]);

        $link = SpeakerLink::forBlock($block);

        $this->assertNotNull($link);
        // The window, not the whole exercise track: without it the figure says
        // its line and then stands in silence for the rest of the recording.
        $this->assertStringContainsString('from=1200', $link['url']);
        $this->assertStringContainsString('to=4300', $link['url']);
    }

    public function test_a_block_with_no_recording_gets_no_speaker(): void
    {
        $block = $this->block('repeat_after_speaker', ['targets' => ['Say something.']]);

        // A silent presenter is a face with no reason to be there.
        $this->assertNull(SpeakerLink::forBlock($block));
    }

    public function test_a_block_where_a_face_is_decoration_gets_no_speaker(): void
    {
        $block = $this->block('source_text', [
            'audio_media_asset_id' => $this->audio()->id,
        ]);

        // Reading a passage does not need a talking head, and a talking head
        // that downloads a 3D renderer is not free.
        $this->assertNull(SpeakerLink::forBlock($block));
    }

    public function test_no_block_at_all_is_handled(): void
    {
        $this->assertNull(SpeakerLink::forBlock(null));
    }
}
