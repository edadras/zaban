<?php

namespace Tests\Feature\Panel;

use App\Models\ClassGroup;
use App\Models\ClassSession;
use App\Models\SchoolMember;
use App\Services\Classroom\ClassScheduleService;
use App\Services\Classroom\SchoolService;
use Carbon\CarbonImmutable;

/**
 * The coach's classes, driven from the browser: the roll and the timetable.
 */
class PanelClassTest extends PanelTestCase
{
    public function test_the_coach_sees_their_own_classes_and_not_anothers(): void
    {
        $rival = app(SchoolService::class)
            ->create($this->outsider, 'Rival Institute');

        $theirs = ClassGroup::create([
            'school_id' => $rival->id,
            'coach_id' => $this->outsider->id,
            'title' => 'Somebody else\'s class',
            'capacity' => 10,
            'timezone' => 'Asia/Tehran',
        ]);

        $this->actingAs($this->coach)
            ->get(route('panel.classes.index'))
            ->assertOk()
            ->assertSee($this->group->title)
            ->assertDontSee($theirs->title);

        $this->actingAs($this->coach)
            ->get(route('panel.classes.show', $theirs))
            ->assertForbidden();
    }

    public function test_the_owner_makes_a_class(): void
    {
        $this->actingAs($this->owner)
            ->post(route('panel.classes.store'), [
                'school_id' => $this->school->id,
                'coach_id' => $this->coach->id,
                'title' => 'شنبه‌ها A2',
                'capacity' => 12,
            ])
            ->assertSessionHas('status');

        $this->assertDatabaseHas('class_groups', [
            'title' => 'شنبه‌ها A2',
            'coach_id' => $this->coach->id,
            'school_id' => $this->school->id,
        ]);
    }

    /** A class is taught by somebody this school teaches with. */
    public function test_a_class_cannot_be_pointed_at_a_stranger(): void
    {
        $this->actingAs($this->owner)
            ->post(route('panel.classes.store'), [
                'school_id' => $this->school->id,
                'coach_id' => $this->outsider->id,
                'title' => 'Nope',
            ])
            ->assertForbidden();
    }

    public function test_a_weekly_time_fills_the_calendar(): void
    {
        $this->actingAs($this->coach)
            ->post(route('panel.classes.rules.store', $this->group), [
                'weekday' => 2,
                'start_time' => '18:00',
                'duration_minutes' => 90,
                'starts_on' => now()->toDateString(),
                'generate_weeks' => 4,
            ])
            ->assertSessionHas('status');

        $sessions = ClassSession::where('class_group_id', $this->group->id)->get();

        $this->assertGreaterThanOrEqual(4, $sessions->count());

        foreach ($sessions as $session) {
            $this->assertSame(
                2,
                CarbonImmutable::parse($session->starts_at)->setTimezone('Asia/Tehran')->dayOfWeek,
            );
        }
    }

    public function test_withdrawing_a_time_cancels_only_the_classes_still_to_come(): void
    {
        $this->actingAs($this->coach)->post(route('panel.classes.rules.store', $this->group), [
            'weekday' => 1,
            'start_time' => '09:00',
            'duration_minutes' => 60,
            'starts_on' => now()->toDateString(),
            'generate_weeks' => 4,
        ]);

        $rule = $this->group->rules()->firstOrFail();

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
            ->delete(route('panel.classes.rules.destroy', [$this->group, $rule]))
            ->assertSessionHas('status');

        $this->assertSame(ClassSession::ENDED, $taught->fresh()->status);
        $this->assertSame(0, ClassSession::where('schedule_rule_id', $rule->id)
            ->where('starts_at', '>', now())
            ->where('status', ClassSession::SCHEDULED)->count());
    }

    public function test_only_the_schools_own_learners_can_be_enrolled(): void
    {
        $this->group->students()->detach();

        $this->actingAs($this->coach)
            ->post(route('panel.classes.enrol', $this->group), [
                'user_ids' => [$this->outsider->id, $this->student->id],
            ])
            ->assertSessionHas('rejected', [$this->outsider->id]);

        $this->assertTrue($this->group->students()->whereKey($this->student->id)->exists());
        $this->assertFalse($this->group->students()->whereKey($this->outsider->id)->exists());
    }

    public function test_a_class_will_not_take_more_learners_than_it_seats(): void
    {
        $this->group->update(['capacity' => 2]);
        $third = $this->makeUser('Third student');
        app(SchoolService::class)
            ->addMember($this->school, $third, SchoolMember::STUDENT);

        $this->actingAs($this->coach)
            ->post(route('panel.classes.enrol', $this->group), ['user_ids' => [$third->id]])
            ->assertSessionHasErrors('classroom');
    }

    public function test_a_learner_can_be_withdrawn(): void
    {
        $this->actingAs($this->coach)
            ->delete(route('panel.classes.withdraw', [$this->group, $this->student]))
            ->assertSessionHas('status');

        $this->assertFalse($this->group->students()->whereKey($this->student->id)->exists());
    }
}
