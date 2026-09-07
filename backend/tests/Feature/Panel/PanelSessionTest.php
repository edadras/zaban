<?php

namespace Tests\Feature\Panel;

use App\Models\ClassMaterial;
use App\Models\ClassSession;
use App\Models\MediaAsset;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Preparing and opening a class from the browser.
 *
 * The prep page is why a coach wants a website at all, so what is tested is the
 * part a phone cannot do: putting a file on the shelf, and the room page giving
 * the browser a key that is narrow and does not last.
 */
class PanelSessionTest extends PanelTestCase
{
    public function test_the_coach_pins_some_text_to_the_lesson(): void
    {
        $session = $this->makeSession();

        $this->actingAs($this->coach)
            ->post(route('panel.sessions.materials.store', $session), [
                'kind' => 'text',
                'title' => 'واژه‌های امروز',
                'body' => 'Some text to read.',
            ])
            ->assertSessionHas('status');

        $this->assertDatabaseHas('class_materials', [
            'class_session_id' => $session->id,
            'title' => 'واژه‌های امروز',
            'kind' => 'text',
        ]);
    }

    public function test_an_uploaded_video_becomes_a_media_asset(): void
    {
        Storage::fake(config('filesystems.default'));

        $session = $this->makeSession();

        $this->actingAs($this->coach)
            ->post(route('panel.sessions.materials.store', $session), [
                'kind' => 'video',
                'title' => 'ضبط تخته',
                'file' => UploadedFile::fake()->create('board.mp4', 128, 'video/mp4'),
            ])
            ->assertSessionHas('status');

        $material = ClassMaterial::where('class_session_id', $session->id)->firstOrFail();

        $this->assertNotNull($material->media_asset_id);
        $this->assertSame('video', $material->media->type);
        $this->assertSame('coach_upload', $material->media->origin);
    }

    /** A material that points at nothing is not a material. */
    public function test_an_empty_material_is_refused(): void
    {
        $session = $this->makeSession();

        $this->actingAs($this->coach)
            ->post(route('panel.sessions.materials.store', $session), [
                'kind' => 'text',
                'title' => 'Nothing at all',
            ])
            ->assertSessionHasErrors('classroom');

        $this->assertDatabaseCount('class_materials', 0);
    }

    public function test_starting_the_class_tells_the_roll_and_opens_the_room(): void
    {
        $session = $this->makeSession();

        $this->actingAs($this->coach)
            ->post(route('panel.sessions.start', $session))
            ->assertRedirect(route('panel.sessions.room', $session));

        $this->assertSame(ClassSession::LIVE, $session->fresh()->status);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $this->student->id]);
    }

    /**
     * The room page hands the browser a key, and it is a narrow one.
     *
     * Named so it can be recognised, scoped to the classroom, expiring with the
     * class, and replacing its predecessor rather than piling up.
     */
    public function test_the_room_page_mints_one_short_lived_key(): void
    {
        $session = $this->makeSession();
        $this->actingAs($this->coach)->post(route('panel.sessions.start', $session));

        $this->actingAs($this->coach)->get(route('panel.sessions.room', $session))->assertOk();
        $this->actingAs($this->coach)->get(route('panel.sessions.room', $session))->assertOk();

        $tokens = PersonalAccessToken::where('tokenable_id', $this->coach->id)
            ->where('name', 'panel-room')->get();

        $this->assertCount(1, $tokens, 'a second visit replaces the key rather than adding one');
        $this->assertSame(['classroom'], $tokens->first()->abilities);
        $this->assertNotNull($tokens->first()->expires_at);
        $this->assertTrue($tokens->first()->expires_at->lessThan(now()->addHours(7)));
    }

    public function test_signing_out_takes_the_room_key_with_it(): void
    {
        $session = $this->makeSession();
        $this->actingAs($this->coach)->post(route('panel.sessions.start', $session));
        $this->actingAs($this->coach)->get(route('panel.sessions.room', $session));

        $this->actingAs($this->coach)->post(route('panel.logout'));

        $this->assertSame(0, PersonalAccessToken::where('tokenable_id', $this->coach->id)
            ->where('name', 'panel-room')->count());
    }

    public function test_somebody_elses_class_is_not_yours_to_prepare(): void
    {
        $session = $this->makeSession();

        $this->actingAs($this->student)->get(route('panel.sessions.show', $session))->assertForbidden();

        $this->actingAs($this->outsider)
            ->get(route('panel.sessions.show', $session))
            ->assertForbidden();
    }

    public function test_the_school_manager_can_cover_a_coachs_class(): void
    {
        $session = $this->makeSession();

        $this->actingAs($this->owner)->get(route('panel.sessions.show', $session))->assertOk();
        $this->actingAs($this->owner)->post(route('panel.sessions.start', $session))->assertRedirect();
    }

    public function test_attendance_counts_only_the_time_in_the_room(): void
    {
        $session = $this->makeSession();
        $this->actingAs($this->coach)->post(route('panel.sessions.start', $session));

        $this->actingAs($this->student)
            ->postJson("/api/v1/class-sessions/{$session->id}/room/join");

        $this->travel(10)->minutes();

        $this->actingAs($this->coach)->post(route('panel.sessions.end', $session))
            ->assertRedirect(route('panel.sessions.attendance', $session));

        $this->actingAs($this->coach)
            ->get(route('panel.sessions.attendance', $session))
            ->assertOk()
            ->assertSee($this->student->name);
    }

    public function test_the_coach_summons_a_few_rather_than_everyone(): void
    {
        $session = $this->makeSession();
        $this->actingAs($this->coach)->post(route('panel.sessions.start', $session));

        DB::table('notifications')->delete();

        $this->actingAs($this->coach)
            ->post(route('panel.sessions.summon', $session), ['user_ids' => [$this->student->id]])
            ->assertSessionHas('status');

        $this->assertDatabaseHas('notifications', ['notifiable_id' => $this->student->id]);
        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $this->otherStudent->id]);
    }

    /**
     * The replay page.
     *
     * A class with no recording is a page that says so rather than a broken
     * player, and one with a recording hands out a link that expires.
     */
    public function test_the_replay_page_says_when_there_is_nothing_to_replay(): void
    {
        $session = $this->makeSession();

        $this->actingAs($this->coach)
            ->get(route('panel.sessions.recording', $session))
            ->assertOk()
            ->assertSee('ضبط نشده');
    }

    public function test_the_replay_page_plays_a_finished_recording(): void
    {
        $session = $this->makeSession();

        $asset = MediaAsset::create([
            'disk' => 'recordings',
            'path' => 'class-1.mp4',
            'type' => 'video',
            'mime' => 'video/mp4',
            'bytes' => 18,
            'origin' => 'class_recording',
            'copyright_status' => 'owned',
        ]);

        $session->forceFill([
            'status' => ClassSession::ENDED,
            'recording_media_asset_id' => $asset->id,
            'recording_status' => ClassSession::RECORDING_READY,
            'recording_duration_ms' => 5_400_000,
        ])->save();

        $this->actingAs($this->owner)
            ->get(route('panel.sessions.recording', $session))
            ->assertOk()
            ->assertSee('<video', false)
            ->assertSee('signature');
    }

    public function test_somebody_elses_recording_is_not_yours_to_watch(): void
    {
        $session = $this->makeSession();

        $this->actingAs($this->outsider)
            ->get(route('panel.sessions.recording', $session))
            ->assertForbidden();
    }

    public function test_the_shelf_can_be_reordered(): void
    {
        $session = $this->makeSession();

        $first = ClassMaterial::create([
            'class_session_id' => $session->id, 'uploaded_by' => $this->coach->id,
            'kind' => 'text', 'title' => 'One', 'body' => 'a', 'position' => 0,
        ]);
        $second = ClassMaterial::create([
            'class_session_id' => $session->id, 'uploaded_by' => $this->coach->id,
            'kind' => 'text', 'title' => 'Two', 'body' => 'b', 'position' => 1,
        ]);

        $this->actingAs($this->coach)
            ->postJson(route('panel.sessions.materials.reorder', $session), [
                'order' => [$second->id, $first->id],
            ])
            ->assertOk();

        $this->assertSame(0, $second->fresh()->position);
        $this->assertSame(1, $first->fresh()->position);
    }
}
