<?php

namespace Tests\Feature\Classroom;

use App\Events\Classroom\ClassroomEvent;
use App\Models\ClassMaterial;
use App\Models\ClassParticipant;
use App\Models\ClassSession;
use App\Services\Live\LiveRoomProvider;
use App\Services\Live\NullRoomProvider;
use Illuminate\Support\Facades\Event;

/**
 * Running a class.
 *
 * The parts that decide whether a lesson is teachable: can the coach silence a
 * room, does a learner who reconnects come back the way they were left, and can
 * anybody who is not in the class see any of it.
 */
class LiveClassroomTest extends ClassroomTestCase
{
    public function test_the_coach_opens_the_class_and_everyone_on_the_roll_is_told(): void
    {
        $session = $this->makeSession();

        $response = $this->actingAs($this->coach)
            ->postJson("/api/v1/class-sessions/{$session->id}/start");

        $response->assertOk();
        $this->assertSame(ClassSession::LIVE, $response->json('data.session.status'));
        $this->assertSame(2, $response->json('data.notified'));

        $this->assertDatabaseHas('notifications', ['notifiable_id' => $this->student->id]);
    }

    /** A learner arrives able to hear and see, and to say nothing. */
    public function test_a_learner_joins_muted(): void
    {
        $session = $this->makeSession();
        $this->actingAs($this->coach)->postJson("/api/v1/class-sessions/{$session->id}/start");

        $response = $this->actingAs($this->student)
            ->postJson("/api/v1/class-sessions/{$session->id}/room/join");

        $response->assertOk();
        $this->assertFalse($response->json('data.participant.can_publish_audio'));
        $this->assertFalse($response->json('data.participant.can_publish_video'));
        $this->assertTrue($response->json('data.participant.is_present'));
    }

    public function test_the_coach_hands_out_and_takes_back_the_microphone(): void
    {
        [$session, $participant] = $this->joinedStudent();

        $this->actingAs($this->coach)->postJson(
            "/api/v1/class-sessions/{$session->id}/room/participants/{$participant->id}/media",
            ['audio' => true],
        )->assertOk()->assertJsonPath('data.can_publish_audio', true);

        $this->actingAs($this->coach)->postJson(
            "/api/v1/class-sessions/{$session->id}/room/participants/{$participant->id}/media",
            ['audio' => false],
        )->assertOk()->assertJsonPath('data.can_publish_audio', false);
    }

    /**
     * The decision has to survive the connection.
     *
     * This is the whole reason the permission is a row rather than a message:
     * a learner who is muted and then reloads must not come back able to talk.
     */
    public function test_a_muted_learner_who_rejoins_is_still_muted(): void
    {
        [$session, $participant] = $this->joinedStudent();

        $this->actingAs($this->coach)->postJson(
            "/api/v1/class-sessions/{$session->id}/room/participants/{$participant->id}/media",
            ['audio' => true],
        );
        $this->actingAs($this->coach)->postJson(
            "/api/v1/class-sessions/{$session->id}/room/participants/{$participant->id}/media",
            ['audio' => false],
        );

        $this->actingAs($this->student)->postJson("/api/v1/class-sessions/{$session->id}/room/leave");

        $this->actingAs($this->student)
            ->postJson("/api/v1/class-sessions/{$session->id}/room/join")
            ->assertOk()
            ->assertJsonPath('data.participant.can_publish_audio', false);
    }

    public function test_mute_all_silences_the_room_but_not_the_coach(): void
    {
        [$session, $participant] = $this->joinedStudent();

        $this->actingAs($this->coach)->postJson(
            "/api/v1/class-sessions/{$session->id}/room/participants/{$participant->id}/media",
            ['audio' => true],
        );

        $this->actingAs($this->coach)
            ->postJson("/api/v1/class-sessions/{$session->id}/room/mute-all")
            ->assertOk();

        $this->assertFalse($participant->fresh()->can_publish_audio);

        $coachSeat = ClassParticipant::where('class_session_id', $session->id)
            ->where('user_id', $this->coach->id)->first();
        $this->assertTrue($coachSeat->can_publish_audio);
    }

    /** The coach's decision has to reach the media server, not just the table. */
    public function test_muting_is_pushed_to_the_media_server(): void
    {
        [$session, $participant] = $this->joinedStudent();

        // The null provider records rather than sends; swap in one that claims
        // to be configured so the service takes the path it would in production.
        $provider = new class extends NullRoomProvider
        {
            public function isConfigured(): bool
            {
                return true;
            }
        };
        $this->app->instance(LiveRoomProvider::class, $provider);

        $this->actingAs($this->coach)->postJson(
            "/api/v1/class-sessions/{$session->id}/room/participants/{$participant->id}/media",
            ['audio' => false],
        )->assertOk();

        $methods = array_column($provider->calls, 0);
        $this->assertContains('updatePermissions', $methods);
        $this->assertContains('muteTrack', $methods);
    }

    public function test_a_learner_cannot_hand_themselves_the_microphone(): void
    {
        [$session, $participant] = $this->joinedStudent();

        $this->actingAs($this->student)->postJson(
            "/api/v1/class-sessions/{$session->id}/room/participants/{$participant->id}/media",
            ['audio' => true],
        )->assertStatus(403);

        $this->assertFalse($participant->fresh()->can_publish_audio);
    }

    public function test_somebody_from_outside_the_class_cannot_see_the_room(): void
    {
        $session = $this->makeSession();
        $this->actingAs($this->coach)->postJson("/api/v1/class-sessions/{$session->id}/start");

        $this->actingAs($this->outsider)
            ->getJson("/api/v1/class-sessions/{$session->id}/room")
            ->assertStatus(403);

        $this->actingAs($this->outsider)
            ->postJson("/api/v1/class-sessions/{$session->id}/room/join")
            ->assertStatus(403);
    }

    /** Sharing a second thing puts the first away. */
    public function test_only_one_material_is_on_screen_at_a_time(): void
    {
        $session = $this->makeSession();
        $this->actingAs($this->coach)->postJson("/api/v1/class-sessions/{$session->id}/start");

        $first = $this->makeMaterial($session, 'Slide one');
        $second = $this->makeMaterial($session, 'Slide two');

        $this->actingAs($this->coach)
            ->postJson("/api/v1/class-sessions/{$session->id}/room/materials/{$first->id}/share")
            ->assertOk();
        $this->actingAs($this->coach)
            ->postJson("/api/v1/class-sessions/{$session->id}/room/materials/{$second->id}/share")
            ->assertOk();

        $this->assertNull($first->fresh()->shared_at);
        $this->assertNotNull($second->fresh()->shared_at);
    }

    /** A learner sees what is on screen, not the coach's whole shelf. */
    public function test_a_learner_only_sees_shared_material(): void
    {
        $session = $this->makeSession();
        $this->actingAs($this->coach)->postJson("/api/v1/class-sessions/{$session->id}/start");

        $shown = $this->makeMaterial($session, 'On screen');
        $this->makeMaterial($session, 'Still on the shelf');

        $this->actingAs($this->coach)
            ->postJson("/api/v1/class-sessions/{$session->id}/room/materials/{$shown->id}/share");

        $response = $this->actingAs($this->student)
            ->getJson("/api/v1/class-sessions/{$session->id}/room")
            ->assertOk();

        $this->assertCount(1, $response->json('data.materials'));
        $this->assertSame('On screen', $response->json('data.materials.0.title'));

        $coachView = $this->actingAs($this->coach)
            ->getJson("/api/v1/class-sessions/{$session->id}/room")->assertOk();
        $this->assertCount(2, $coachView->json('data.materials'));
    }

    public function test_the_room_broadcasts_what_happens_in_it(): void
    {
        Event::fake([ClassroomEvent::class]);

        $session = $this->makeSession();
        $this->actingAs($this->coach)->postJson("/api/v1/class-sessions/{$session->id}/start");

        Event::assertDispatched(
            ClassroomEvent::class,
            fn (ClassroomEvent $e) => $e->type === ClassroomEvent::SESSION_STARTED
                && $e->classSessionId === $session->id,
        );
    }

    /** Time in the room is added up across reconnections, not measured end to end. */
    public function test_attendance_counts_the_time_actually_present(): void
    {
        [$session, $participant] = $this->joinedStudent();

        $this->travel(10)->minutes();
        $this->actingAs($this->student)->postJson("/api/v1/class-sessions/{$session->id}/room/leave");

        $this->travel(30)->minutes();   // away
        $this->actingAs($this->student)->postJson("/api/v1/class-sessions/{$session->id}/room/join");

        $this->travel(5)->minutes();
        $this->actingAs($this->coach)->postJson("/api/v1/class-sessions/{$session->id}/end");

        $seconds = $participant->fresh()->seconds_present;

        // Fifteen minutes present, not the forty-five that elapsed.
        $this->assertGreaterThanOrEqual(890, $seconds);
        $this->assertLessThan(1000, $seconds);
    }

    public function test_ending_the_class_closes_it_for_everyone(): void
    {
        [$session] = $this->joinedStudent();

        $this->actingAs($this->coach)
            ->postJson("/api/v1/class-sessions/{$session->id}/end")
            ->assertOk()
            ->assertJsonPath('data.status', ClassSession::ENDED);

        $this->assertFalse($session->fresh()->isJoinable());
        $this->assertSame(0, ClassParticipant::where('class_session_id', $session->id)
            ->where('is_present', true)->count());
    }

    // ------------------------------------------------------------- helpers

    /** @return array{0: ClassSession, 1: ClassParticipant} */
    private function joinedStudent(): array
    {
        $session = $this->makeSession();
        $this->actingAs($this->coach)->postJson("/api/v1/class-sessions/{$session->id}/start");
        $this->actingAs($this->student)->postJson("/api/v1/class-sessions/{$session->id}/room/join");

        $participant = ClassParticipant::where('class_session_id', $session->id)
            ->where('user_id', $this->student->id)->firstOrFail();

        return [$session, $participant];
    }

    private function makeMaterial(ClassSession $session, string $title): ClassMaterial
    {
        return ClassMaterial::create([
            'class_session_id' => $session->id,
            'uploaded_by' => $this->coach->id,
            'kind' => 'text',
            'title' => $title,
            'body' => 'Some text to read.',
        ]);
    }
}
