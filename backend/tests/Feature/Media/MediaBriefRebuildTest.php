<?php

namespace Tests\Feature\Media;

use App\Models\Character;
use App\Models\MediaBrief;
use App\Services\Media\MediaBriefBuilder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Re-planning must not re-bill.
 *
 * Every brief in the catalogue - all twenty-four thousand of them - ends with
 * the same shared list of exclusions, so adding one word to that list changes
 * every prompt at once. If a changed prompt requeued the work it replaces, that
 * one-word edit would silently order the whole catalogue a second time, and at
 * video prices the bill runs to thousands of credits before anyone sees a
 * picture. These tests hold that shut.
 */
class MediaBriefRebuildTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
    }

    private function cast(): Character
    {
        return Character::create([
            'slug' => 'maya',
            'name' => 'Maya',
            'persona' => 'the learner\'s counterpart',
            'appearance_prompt' => 'a woman in her early thirties in an olive-green jumper',
        ]);
    }

    /** A brief in the state the importer leaves behind: rendered, and paid for. */
    private function rendered(MediaBriefBuilder $builder): MediaBrief
    {
        $this->cast();
        $builder->buildCharacterPortraits();

        $brief = MediaBrief::where('kind', MediaBrief::KIND_CHARACTER_PORTRAIT)->firstOrFail();
        $brief->update([
            'status' => MediaBrief::STATUS_IMPORTED,
            'generated_at' => now(),
            'result_url' => 'https://cdn.example/maya.png',
        ]);

        return $brief->fresh();
    }

    public function test_rebuilding_an_unchanged_brief_changes_nothing(): void
    {
        $builder = app(MediaBriefBuilder::class);
        $brief = $this->rendered($builder);

        app(MediaBriefBuilder::class)->buildCharacterPortraits();

        $this->assertSame(MediaBrief::STATUS_IMPORTED, $brief->fresh()->status);
    }

    public function test_a_changed_prompt_leaves_work_already_rendered_alone(): void
    {
        $builder = app(MediaBriefBuilder::class);
        $brief = $this->rendered($builder);

        // What an edit to the shared exclusion list looks like from here: the
        // request genuinely differs, and the picture on disk is still fine.
        $brief->update(['request_hash' => str_repeat('0', 64)]);

        $second = app(MediaBriefBuilder::class);
        $second->buildCharacterPortraits();

        $after = $brief->fresh();
        $this->assertSame(MediaBrief::STATUS_IMPORTED, $after->status, 'a rendered brief must not be requeued');
        $this->assertSame('https://cdn.example/maya.png', $after->result_url);
        $this->assertSame(1, $second->staleCount(), 'and the operator must be told it is now out of date');
    }

    public function test_a_changed_prompt_does_requeue_when_it_is_asked_for(): void
    {
        $builder = app(MediaBriefBuilder::class);
        $brief = $this->rendered($builder);
        $brief->update(['request_hash' => str_repeat('0', 64)]);

        app(MediaBriefBuilder::class)->rerenderPaidWork()->buildCharacterPortraits();

        // Deliberate, and charged: the old result is for a different picture.
        $after = $brief->fresh();
        $this->assertSame(MediaBrief::STATUS_PENDING, $after->status);
        $this->assertNull($after->result_url);
    }

    public function test_an_abandoned_claim_comes_back_to_the_queue(): void
    {
        $this->cast();
        app(MediaBriefBuilder::class)->buildCharacterPortraits();

        $brief = MediaBrief::firstOrFail();
        $brief->update(['status' => MediaBrief::STATUS_GENERATING]);
        // An export from yesterday whose render round never came back.
        MediaBrief::where('id', $brief->id)->update(['updated_at' => now()->subDay()]);

        $this->artisan('media:manifest', ['--limit' => 1, '--release' => 6]);

        // Otherwise it is claimed for ever: renderable() skips it, the manifest
        // never offers it again, and that lesson silently never gets a picture.
        $this->assertSame(MediaBrief::STATUS_PENDING, $brief->fresh()->status);
    }

    public function test_a_claim_that_is_still_warm_is_left_alone(): void
    {
        $this->cast();
        app(MediaBriefBuilder::class)->buildCharacterPortraits();

        $brief = MediaBrief::firstOrFail();
        $brief->update(['status' => MediaBrief::STATUS_GENERATING]);

        $this->artisan('media:manifest', ['--limit' => 1, '--release' => 6]);

        // A round in flight must not be handed to a second runner: that pays
        // twice for the same image.
        $this->assertSame(MediaBrief::STATUS_GENERATING, $brief->fresh()->status);
    }

    public function test_a_deliberate_skip_survives_re_planning(): void
    {
        $this->cast();
        app(MediaBriefBuilder::class)->buildCharacterPortraits();
        $brief = MediaBrief::firstOrFail();

        $this->artisan('media:skip', [
            'ids' => [$brief->id],
            '--reason' => 'teaches written text; artwork may not contain writing',
        ])->assertExitCode(0);

        // The change that used to undo it: a rebuild after the prompt moved on.
        $brief->update(['request_hash' => str_repeat('0', 64)]);
        $second = app(MediaBriefBuilder::class);
        $second->buildCharacterPortraits();

        $after = $brief->fresh();
        $this->assertSame(MediaBrief::STATUS_SKIPPED, $after->status);
        $this->assertStringContainsString('written text', (string) $after->skip_reason);
        $this->assertSame(1, $second->lockedCount());
    }

    public function test_a_skip_must_say_why(): void
    {
        $this->cast();
        app(MediaBriefBuilder::class)->buildCharacterPortraits();
        $brief = MediaBrief::firstOrFail();

        // A permanent decision with no reason is indistinguishable from a slip.
        $this->artisan('media:skip', ['ids' => [$brief->id]])->assertExitCode(1);

        $this->assertSame(MediaBrief::STATUS_PENDING, $brief->fresh()->status);
    }

    public function test_a_skip_will_not_throw_away_work_already_paid_for(): void
    {
        $builder = app(MediaBriefBuilder::class);
        $brief = $this->rendered($builder);

        $this->artisan('media:skip', ['ids' => [$brief->id], '--reason' => 'changed my mind'])
            ->assertExitCode(0);

        // The artwork exists and is attached; skipping it now would take a
        // picture off a lesson to save money that is already spent.
        $this->assertSame(MediaBrief::STATUS_IMPORTED, $brief->fresh()->status);
    }

    public function test_a_skip_can_be_undone(): void
    {
        $this->cast();
        app(MediaBriefBuilder::class)->buildCharacterPortraits();
        $brief = MediaBrief::firstOrFail();

        $this->artisan('media:skip', ['ids' => [$brief->id], '--reason' => 'on reflection, no'])
            ->assertExitCode(0);
        $this->artisan('media:skip', ['ids' => [$brief->id], '--unskip' => true])
            ->assertExitCode(0);

        $after = $brief->fresh();
        $this->assertSame(MediaBrief::STATUS_PENDING, $after->status);
        $this->assertFalse($after->skip_locked);
    }

    public function test_a_reworded_scene_reaches_a_rendered_brief_without_requeueing_it(): void
    {
        $builder = app(MediaBriefBuilder::class);
        $brief = $this->rendered($builder);

        // What a change to the scene line looks like from here: the prompt that
        // was paid for is identical, only the one-line description a contact
        // sheet prints per cell has been reworded.
        $brief->update(['scene' => 'something written before the wording improved']);

        $second = app(MediaBriefBuilder::class);
        $second->buildCharacterPortraits();

        $after = $brief->fresh();
        $this->assertNotSame('something written before the wording improved', $after->scene);
        $this->assertSame(MediaBrief::STATUS_IMPORTED, $after->status, 'and it must not be reordered for it');
        $this->assertSame('https://cdn.example/maya.png', $after->result_url);
        $this->assertSame(0, $second->staleCount());
    }

    public function test_a_brief_that_was_never_rendered_is_always_refreshed(): void
    {
        $builder = app(MediaBriefBuilder::class);
        $this->rendered($builder);

        $brief = MediaBrief::firstOrFail();
        $brief->update([
            'status' => MediaBrief::STATUS_FAILED,
            'request_hash' => str_repeat('0', 64),
            'result_url' => null,
        ]);

        $second = app(MediaBriefBuilder::class);
        $second->buildCharacterPortraits();

        // Nothing was paid for here, so there is nothing to protect.
        $this->assertSame(MediaBrief::STATUS_PENDING, $brief->fresh()->status);
        $this->assertSame(0, $second->staleCount());
    }
}
