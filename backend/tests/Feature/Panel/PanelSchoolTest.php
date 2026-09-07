<?php

namespace Tests\Feature\Panel;

use App\Models\CoachStudent;
use App\Models\SchoolMember;
use App\Services\Classroom\SchoolService;

/**
 * Running a school from a browser.
 *
 * The same rules as the API, asked through forms: an administrator adds people
 * to their own school and nobody else's, and adding somebody never invents an
 * account for them.
 */
class PanelSchoolTest extends PanelTestCase
{
    public function test_the_owner_adds_a_coach_by_email(): void
    {
        $newCoach = $this->makeUser('Second coach');

        $this->actingAs($this->owner)
            ->post(route('panel.schools.people.store', $this->school), [
                'email' => $newCoach->email,
                'role' => 'coach',
            ])
            ->assertRedirect(route('panel.schools.people', $this->school))
            ->assertSessionHas('status');

        $this->assertDatabaseHas('school_members', [
            'school_id' => $this->school->id,
            'user_id' => $newCoach->id,
            'role' => 'coach',
        ]);
    }

    public function test_adding_an_unknown_email_does_not_invent_an_account(): void
    {
        $this->actingAs($this->owner)
            ->post(route('panel.schools.people.store', $this->school), [
                'email' => 'nobody@example.test',
                'role' => 'coach',
            ])
            ->assertSessionHasErrors('email');

        $this->assertDatabaseMissing('users', ['email' => 'nobody@example.test']);
    }

    public function test_a_coach_cannot_manage_the_roll(): void
    {
        $this->actingAs($this->coach)
            ->get(route('panel.schools.people', $this->school))
            ->assertForbidden();

        $this->actingAs($this->coach)
            ->post(route('panel.schools.people.store', $this->school), [
                'email' => $this->outsider->email,
                'role' => 'student',
            ])
            ->assertForbidden();
    }

    public function test_one_schools_owner_cannot_reach_another(): void
    {
        $rival = app(SchoolService::class)
            ->create($this->outsider, 'Rival Institute');

        $this->actingAs($this->owner)->get(route('panel.schools.show', $rival))->assertForbidden();
        $this->actingAs($this->owner)->get(route('panel.schools.people', $rival))->assertForbidden();
    }

    public function test_the_admin_attaches_a_learner_to_a_coach(): void
    {
        $this->actingAs($this->owner)
            ->post(route('panel.schools.coach.assign', [$this->school, $this->coach]), [
                'student_id' => $this->student->id,
                'note' => 'برای آزمون بهمن.',
            ])
            ->assertSessionHas('status');

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
            ->post(route('panel.schools.coach.assign', [$this->school, $this->coach]), [
                'student_id' => $this->outsider->id,
            ])
            ->assertSessionHasErrors('classroom');

        $this->assertDatabaseCount('coach_students', 0);
    }

    public function test_the_link_can_be_ended(): void
    {
        $this->actingAs($this->owner)
            ->post(route('panel.schools.coach.assign', [$this->school, $this->coach]), [
                'student_id' => $this->student->id,
            ]);

        $this->actingAs($this->owner)
            ->delete(route('panel.schools.coach.unassign', [$this->school, $this->coach, $this->student]))
            ->assertSessionHas('status');

        $this->assertNotNull(CoachStudent::first()->ended_at);
    }

    public function test_a_school_cannot_be_left_without_an_owner(): void
    {
        $member = SchoolMember::where('school_id', $this->school->id)
            ->where('role', SchoolMember::OWNER)->firstOrFail();

        $this->actingAs($this->owner)
            ->delete(route('panel.schools.people.destroy', [$this->school, $member]))
            ->assertSessionHasErrors('classroom');

        $this->assertDatabaseHas('school_members', ['id' => $member->id]);
    }

    public function test_the_coach_page_lists_who_they_look_after(): void
    {
        $this->actingAs($this->owner)
            ->post(route('panel.schools.coach.assign', [$this->school, $this->coach]), [
                'student_id' => $this->student->id,
            ]);

        $this->actingAs($this->owner)
            ->get(route('panel.schools.coach', [$this->school, $this->coach]))
            ->assertOk()
            ->assertSee($this->student->name);
    }

    public function test_a_new_school_belongs_to_whoever_made_it(): void
    {
        $this->actingAs($this->coach)
            ->post(route('panel.schools.store'), ['name' => 'آموزشگاه تازه'])
            ->assertSessionHas('status');

        $this->assertDatabaseHas('schools', [
            'name' => 'آموزشگاه تازه',
            'owner_user_id' => $this->coach->id,
        ]);
    }
}
