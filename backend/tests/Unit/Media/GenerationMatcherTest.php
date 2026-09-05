<?php

namespace Tests\Unit\Media;

use App\Models\MediaBrief;
use App\Services\Media\GenerationMatcher;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The unlimited model packs are only reachable from the Higgsfield web app, so
 * the course is rendered by a person pasting prompts there. That leaves 2,500
 * images in an account history with nothing tying them to a lesson - and the
 * prompt is the only thread that survives. These tests hold that thread.
 */
class GenerationMatcherTest extends TestCase
{
    use RefreshDatabase;

    private GenerationMatcher $matcher;

    private const PROMPT = 'A clear, uncluttered illustrative scene for an English language lesson. '
        .'Teaching context: In the kitchen - Things we use in the kitchen.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        $this->matcher = new GenerationMatcher;
    }

    private function brief(string $prompt, int $subjectId = 1): MediaBrief
    {
        return MediaBrief::create([
            'kind' => MediaBrief::KIND_LESSON_SCENE,
            'subject_type' => 'App\Models\Lesson',
            'subject_id' => $subjectId,
            'model' => 'gpt_image_2',
            'prompt' => $prompt,
            'aspect_ratio' => '16:9',
            'resolution' => '2k',
            'status' => MediaBrief::STATUS_PENDING,
            'request_hash' => str_repeat('a', 64),
        ]);
    }

    public function test_it_matches_a_prompt_pasted_twice_over(): void
    {
        // Every generation observed in the real account had the prompt doubled,
        // which is what pasting into a textarea twice produces. An exact string
        // comparison finds none of them.
        $brief = $this->brief(self::PROMPT);

        $out = $this->matcher->match([
            ['id' => 'g1', 'prompt' => self::PROMPT.self::PROMPT, 'url' => 'https://cdn/1.png'],
        ]);

        $this->assertSame([], $out['unmatched']);
        $this->assertSame('https://cdn/1.png', $out['matched'][$brief->id]['url']);
    }

    public function test_it_matches_a_doubled_prompt_separated_by_a_space(): void
    {
        $brief = $this->brief(self::PROMPT);

        $out = $this->matcher->match([
            ['id' => 'g1', 'prompt' => self::PROMPT.' '.self::PROMPT, 'url' => 'https://cdn/1.png'],
        ]);

        $this->assertArrayHasKey($brief->id, $out['matched']);
    }

    public function test_it_survives_reflowed_whitespace_and_trailing_spaces(): void
    {
        // Newlines do not survive a textarea, and a trailing space is invisible.
        $brief = $this->brief(self::PROMPT);
        $mangled = "  A clear, uncluttered illustrative scene for an English\nlanguage lesson.   "
            ."Teaching context: In the kitchen - Things we use in the kitchen.  ";

        $out = $this->matcher->match([['id' => 'g1', 'prompt' => $mangled, 'url' => 'https://cdn/1.png']]);

        $this->assertArrayHasKey($brief->id, $out['matched']);
    }

    public function test_a_prompt_belonging_to_no_brief_is_reported_not_guessed(): void
    {
        // Filing a stray generation against the wrong lesson would be worse than
        // leaving it out, because nothing downstream would ever flag it.
        $this->brief(self::PROMPT);

        $out = $this->matcher->match([
            ['id' => 'g1', 'prompt' => 'a photo of something else entirely', 'url' => 'https://cdn/1.png'],
        ]);

        $this->assertSame([], $out['matched']);
        $this->assertCount(1, $out['unmatched']);
    }

    public function test_a_reroll_supersedes_the_take_it_replaced(): void
    {
        // History comes back newest-first, so the first match wins and a second
        // attempt at the same prompt does not fight with it.
        $brief = $this->brief(self::PROMPT);

        $out = $this->matcher->match([
            ['id' => 'newer', 'prompt' => self::PROMPT, 'url' => 'https://cdn/newer.png'],
            ['id' => 'older', 'prompt' => self::PROMPT, 'url' => 'https://cdn/older.png'],
        ]);

        $this->assertSame('https://cdn/newer.png', $out['matched'][$brief->id]['url']);
        $this->assertSame(1, $out['already']);
    }

    public function test_skipped_briefs_are_never_matched(): void
    {
        // A skipped brief was deliberately excluded from the course; an image
        // must not resurrect it.
        $this->brief(self::PROMPT)->update([
            'status' => MediaBrief::STATUS_SKIPPED,
            'skip_reason' => 'no usable example sentence',
        ]);

        $out = $this->matcher->match([
            ['id' => 'g1', 'prompt' => self::PROMPT, 'url' => 'https://cdn/1.png'],
        ]);

        $this->assertSame([], $out['matched']);
    }

    public function test_two_briefs_sharing_a_prompt_resolve_deterministically(): void
    {
        $first = $this->brief(self::PROMPT, 1);
        $this->brief(self::PROMPT, 2);

        $a = $this->matcher->match([['id' => 'g', 'prompt' => self::PROMPT, 'url' => 'https://cdn/1.png']]);
        $b = $this->matcher->match([['id' => 'g', 'prompt' => self::PROMPT, 'url' => 'https://cdn/1.png']]);

        $this->assertSame(array_keys($a['matched']), array_keys($b['matched']));
        $this->assertArrayHasKey($first->id, $a['matched']);
    }
}
