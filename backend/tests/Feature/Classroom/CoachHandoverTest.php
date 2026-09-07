<?php

namespace Tests\Feature\Classroom;

use App\Models\ClassSession;
use App\Models\SchoolMember;
use App\Models\User;
use App\Services\Classroom\ClassScheduleService;
use App\Services\Classroom\SchoolService;

/**
 * A coach leaving, and a class changing hands.
 *
 * `class_groups.coach_id` is not nullable, so there is no such thing as a
 * class with nobody teaching it. That makes "remove this coach" a question
 * about their classes as much as about them, and it is the case this module
 * got wrong first: the coach vanished and their classes went on pointing at
 * somebody the school no longer employed.
 */
class CoachHandoverTest extends ClassroomTestCase
{
    public function test_a_coach_who_still_teaches_cannot_simply_be_removed(): void
    {
        $member = $this->coachMember();

        $this->actingAs($this->owner)
            ->deleteJson("/api/v1/schools/{$this->school->id}/members/{$member->id}")
            ->assertStatus(409);

        $this->assertDatabaseHas('school_members', ['id' => $member->id]);
    }

    public function test_moving_the_class_first_lets_the_coach_go(): void
    {
        $successor = $this->makeCoach('Successor');

        $this->actingAs($this->owner)
            ->patchJson("/api/v1/classes/{$this->group->id}", ['coach_id' => $successor->id])
            ->assertOk()
            ->assertJsonPath('data.coach_id', $successor->id);

        $this->actingAs($this->owner)
            ->deleteJson("/api/v1/schools/{$this->school->id}/members/{$this->coachMember()->id}")
            ->assertOk();
    }

    /**
     * The classes that have not been taught follow the new coach; the ones
     * already taught keep the person who taught them, because they are the
     * attendance record of what actually happened.
     */
    public function test_the_untaught_sessions_move_and_the_taught_ones_stay(): void
    {
        $successor = $this->makeCoach('Successor');

        $past = ClassSession::create([
            'class_group_id' => $this->group->id,
            'coach_id' => $this->coach->id,
            'starts_at' => now()->subWeek(),
            'ends_at' => now()->subWeek()->addHour(),
            'status' => ClassSession::ENDED,
            'room_name' => ClassScheduleService::roomName(),
        ]);

        $future = ClassSession::create([
            'class_group_id' => $this->group->id,
            'coach_id' => $this->coach->id,
            'starts_at' => now()->addWeek(),
            'ends_at' => now()->addWeek()->addHour(),
            'status' => ClassSession::SCHEDULED,
            'room_name' => ClassScheduleService::roomName(),
        ]);

        $this->actingAs($this->owner)
            ->patchJson("/api/v1/classes/{$this->group->id}", ['coach_id' => $successor->id])
            ->assertOk();

        $this->assertSame($this->coach->id, $past->fresh()->coach_id);
        $this->assertSame($successor->id, $future->fresh()->coach_id);
    }

    /** A class cannot be handed to somebody who does not teach at the school. */
    public function test_a_class_cannot_be_handed_to_an_outsider(): void
    {
        $this->actingAs($this->owner)
            ->patchJson("/api/v1/classes/{$this->group->id}", ['coach_id' => $this->outsider->id])
            ->assertStatus(422);

        $this->assertSame($this->coach->id, $this->group->fresh()->coach_id);
    }

    /** Handing a class over is a staffing decision, not a teaching one. */
    public function test_a_coach_cannot_give_their_own_class_away(): void
    {
        $successor = $this->makeCoach('Successor');

        $this->actingAs($this->coach)
            ->patchJson("/api/v1/classes/{$this->group->id}", ['coach_id' => $successor->id])
            ->assertStatus(403);

        $this->assertSame($this->coach->id, $this->group->fresh()->coach_id);
    }

    /** But they may still rename their own class. */
    public function test_a_coach_may_still_edit_their_own_class(): void
    {
        $this->actingAs($this->coach)
            ->patchJson("/api/v1/classes/{$this->group->id}", ['title' => 'Tuesday B1 — evening'])
            ->assertOk();

        $this->assertSame('Tuesday B1 — evening', $this->group->fresh()->title);
    }

    /** A closed class is not a reason to keep a coach on the books. */
    public function test_an_inactive_class_does_not_hold_a_coach(): void
    {
        $this->group->update(['is_active' => false]);

        $this->actingAs($this->owner)
            ->deleteJson("/api/v1/schools/{$this->school->id}/members/{$this->coachMember()->id}")
            ->assertOk();
    }

    // ------------------------------------------------------------- helpers

    private function coachMember(): SchoolMember
    {
        return SchoolMember::where('school_id', $this->school->id)
            ->where('user_id', $this->coach->id)
            ->where('role', SchoolMember::COACH)
            ->firstOrFail();
    }

    private function makeCoach(string $name): User
    {
        $user = $this->makeUser($name);

        app(SchoolService::class)
            ->addMember($this->school, $user, SchoolMember::COACH);

        return $user;
    }
}
