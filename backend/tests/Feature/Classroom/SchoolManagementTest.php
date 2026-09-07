<?php

namespace Tests\Feature\Classroom;

use App\Models\ClassSession;
use App\Models\CoachStudent;
use App\Models\SchoolMember;
use App\Services\Classroom\ClassScheduleService;
use Carbon\CarbonImmutable;

/**
 * The school: who may add whom, and who may see what.
 *
 * Most of these are refusals. A module that lets an administrator manage people
 * is a module where the interesting cases are the ones that must not work.
 */
class SchoolManagementTest extends ClassroomTestCase
{
    public function test_the_owner_adds_a_coach_by_email(): void
    {
        $newCoach = $this->makeUser('Second coach');

        $this->actingAs($this->owner)
            ->postJson("/api/v1/schools/{$this->school->id}/members", [
                'email' => $newCoach->email,
                'role' => 'coach',
            ])
            ->assertCreated()
            ->assertJsonPath('data.role', 'coach');

        $this->assertDatabaseHas('school_members', [
            'school_id' => $this->school->id,
            'user_id' => $newCoach->id,
            'role' => 'coach',
        ]);
    }

    /**
     * An email nobody has registered is a 404 that says so.
     *
     * The alternative - creating an account with a password nobody chose - is
     * how a system ends up with users its own owner cannot explain.
     */
    public function test_adding_an_unknown_email_does_not_invent_an_account(): void
    {
        $this->actingAs($this->owner)
            ->postJson("/api/v1/schools/{$this->school->id}/members", [
                'email' => 'nobody@example.test',
                'role' => 'coach',
            ])
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'no_such_account');

        $this->assertDatabaseMissing('users', ['email' => 'nobody@example.test']);
    }

    /** Being a coach at a school does not make you a platform administrator. */
    public function test_the_platform_role_is_left_alone(): void
    {
        $this->actingAs($this->owner)
            ->postJson("/api/v1/schools/{$this->school->id}/members", [
                'email' => $this->student->email,
                'role' => 'coach',
            ])
            ->assertCreated();

        $this->assertSame('learner', $this->student->fresh()->role);
    }

    public function test_a_coach_cannot_manage_the_school(): void
    {
        $this->actingAs($this->coach)
            ->postJson("/api/v1/schools/{$this->school->id}/members", [
                'email' => $this->outsider->email,
                'role' => 'student',
            ])
            ->assertStatus(403);
    }

    public function test_one_schools_admin_cannot_reach_another(): void
    {
        $rival = app(\App\Services\Classroom\SchoolService::class)
            ->create($this->outsider, 'Rival Institute');

        $this->actingAs($this->owner)
            ->getJson("/api/v1/schools/{$rival->id}")
            ->assertStatus(403);

        $this->actingAs($this->owner)
            ->getJson("/api/v1/schools/{$rival->id}/members")
            ->assertStatus(403);
    }

    public function test_the_admin_attaches_a_learner_to_a_coach(): void
    {
        $this->actingAs($this->owner)
            ->postJson("/api/v1/schools/{$this->school->id}/coaches/{$this->coach->id}/students", [
                'student_id' => $this->student->id,
                'note' => 'Wants to sit the exam in Bahman.',
            ])
            ->assertCreated();

        $this->assertDatabaseHas('coach_students', [
            'coach_id' => $this->coach->id,
            'student_id' => $this->student->id,
            'ended_at' => null,
        ]);
    }

    /** Attaching somebody the school does not teach is a privacy hole. */
    public function test_a_learner_from_outside_cannot_be_attached(): void
    {
        $this->actingAs($this->owner)
            ->postJson("/api/v1/schools/{$this->school->id}/coaches/{$this->coach->id}/students", [
                'student_id' => $this->outsider->id,
            ])
            ->assertStatus(422);

        $this->assertDatabaseCount('coach_students', 0);
    }

    public function test_removing_a_coach_ends_their_learners_assignments(): void
    {
        $this->actingAs($this->owner)
            ->postJson("/api/v1/schools/{$this->school->id}/coaches/{$this->coach->id}/students", [
                'student_id' => $this->student->id,
            ]);

        $member = SchoolMember::where('school_id', $this->school->id)
            ->where('user_id', $this->coach->id)->where('role', 'coach')->firstOrFail();

        $this->actingAs($this->owner)
            ->deleteJson("/api/v1/schools/{$this->school->id}/members/{$member->id}")
            ->assertOk();

        $this->assertNotNull(CoachStudent::where('coach_id', $this->coach->id)->first()->ended_at);
    }

    public function test_a_school_cannot_be_left_without_an_owner(): void
    {
        $member = SchoolMember::where('school_id', $this->school->id)
            ->where('role', SchoolMember::OWNER)->firstOrFail();

        $this->actingAs($this->owner)
            ->deleteJson("/api/v1/schools/{$this->school->id}/members/{$member->id}")
            ->assertStatus(409);
    }

    // ------------------------------------------------------------- timetable

    public function test_a_weekly_rule_fills_the_calendar(): void
    {
        $this->actingAs($this->coach)
            ->postJson("/api/v1/classes/{$this->group->id}/rules", [
                'weekday' => 2,               // Tuesday
                'start_time' => '18:00',
                'duration_minutes' => 90,
                'starts_on' => now()->toDateString(),
                'generate_weeks' => 4,
            ])
            ->assertCreated();

        $sessions = ClassSession::where('class_group_id', $this->group->id)->get();

        $this->assertGreaterThanOrEqual(4, $sessions->count());
        $this->assertLessThanOrEqual(5, $sessions->count());

        foreach ($sessions as $session) {
            $this->assertSame(
                2,
                CarbonImmutable::parse($session->starts_at)->setTimezone('Asia/Tehran')->dayOfWeek,
                'every generated session falls on the weekday the rule named',
            );
        }
    }

    /** Running the generator twice must not double the timetable. */
    public function test_generating_twice_creates_nothing_the_second_time(): void
    {
        $this->actingAs($this->coach)
            ->postJson("/api/v1/classes/{$this->group->id}/rules", [
                'weekday' => 3,
                'start_time' => '10:00',
                'duration_minutes' => 60,
                'starts_on' => now()->toDateString(),
                'generate_weeks' => 6,
            ]);

        $before = ClassSession::where('class_group_id', $this->group->id)->count();

        $this->actingAs($this->coach)
            ->postJson("/api/v1/classes/{$this->group->id}/generate", ['weeks' => 6])
            ->assertOk()
            ->assertJsonPath('data.created', 0);

        $this->assertSame($before, ClassSession::where('class_group_id', $this->group->id)->count());
    }

    /** A rule added today must not conjure this morning's class. */
    public function test_the_generator_never_writes_into_the_past(): void
    {
        app(ClassScheduleService::class)->generate($this->group, 4);

        $this->group->rules()->create([
            'weekday' => (int) now('Asia/Tehran')->dayOfWeek,
            'start_time' => '00:01',
            'duration_minutes' => 60,
            'starts_on' => now()->subMonth()->toDateString(),
            'is_active' => true,
        ]);

        app(ClassScheduleService::class)->generate($this->group, 4);

        $this->assertSame(
            0,
            ClassSession::where('class_group_id', $this->group->id)
                ->where('starts_at', '<', now())->count(),
        );
    }

    public function test_withdrawing_a_rule_cancels_only_the_classes_still_to_come(): void
    {
        $this->actingAs($this->coach)
            ->postJson("/api/v1/classes/{$this->group->id}/rules", [
                'weekday' => 1,
                'start_time' => '09:00',
                'duration_minutes' => 60,
                'starts_on' => now()->toDateString(),
                'generate_weeks' => 4,
            ]);

        $rule = $this->group->rules()->firstOrFail();

        // One that has already been taught.
        $taught = ClassSession::create([
            'class_group_id' => $this->group->id,
            'coach_id' => $this->coach->id,
            'schedule_rule_id' => $rule->id,
            'starts_at' => now()->subWeek(),
            'ends_at' => now()->subWeek()->addHour(),
            'status' => ClassSession::ENDED,
            'room_name' => ClassScheduleService::roomName(),
        ]);

        $this->actingAs($this->coach)
            ->deleteJson("/api/v1/classes/{$this->group->id}/rules/{$rule->id}")
            ->assertOk();

        $this->assertSame(ClassSession::ENDED, $taught->fresh()->status);
        $this->assertSame(
            0,
            ClassSession::where('schedule_rule_id', $rule->id)
                ->where('starts_at', '>', now())
                ->where('status', ClassSession::SCHEDULED)->count(),
        );
    }

    public function test_a_class_will_not_take_more_learners_than_it_seats(): void
    {
        $this->group->update(['capacity' => 2]);
        $third = $this->makeUser('Third student');
        app(\App\Services\Classroom\SchoolService::class)
            ->addMember($this->school, $third, SchoolMember::STUDENT);

        $this->actingAs($this->coach)
            ->postJson("/api/v1/classes/{$this->group->id}/students", ['user_ids' => [$third->id]])
            ->assertStatus(422);
    }

    public function test_only_the_schools_own_learners_can_be_enrolled(): void
    {
        $response = $this->actingAs($this->coach)
            ->postJson("/api/v1/classes/{$this->group->id}/students", [
                'user_ids' => [$this->outsider->id],
            ])
            ->assertOk();

        $this->assertSame([$this->outsider->id], $response->json('data.rejected'));
        $this->assertSame([], $response->json('data.enrolled'));
    }
}
