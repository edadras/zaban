<?php

namespace App\Services\Classroom;

use App\Models\ClassGroup;
use App\Models\ClassScheduleRule;
use App\Models\ClassSession;
use App\Models\SchoolMember;
use App\Models\User;

/**
 * The roll and the timetable of one class.
 *
 * Extracted so the API and the web panel enrol a learner by the same rules.
 * Both of the rules here are refusals: a class does not seat more people than
 * it has chairs, and enrolment is not a way to pull an arbitrary account into
 * a school's roll.
 */
class ClassGroupService
{
    public function __construct(private readonly ClassScheduleService $schedule) {}

    /**
     * @param  list<int>  $userIds
     * @return array{enrolled: list<int>, rejected: list<int>}
     */
    public function enrol(ClassGroup $group, array $userIds): array
    {
        $eligible = SchoolMember::where('school_id', $group->school_id)
            ->where('role', SchoolMember::STUDENT)
            ->where('status', 'active')
            ->whereIn('user_id', $userIds)
            ->pluck('user_id');

        $rejected = collect($userIds)->diff($eligible)->values();

        $room = $group->capacity - $group->students()->count();
        if ($eligible->count() > $room) {
            throw new ClassroomException("This class has room for {$room} more.");
        }

        foreach ($eligible as $id) {
            $group->students()->syncWithoutDetaching([
                $id => ['status' => 'enrolled', 'enrolled_at' => now(), 'withdrawn_at' => null],
            ]);
        }

        return ['enrolled' => $eligible->values()->all(), 'rejected' => $rejected->all()];
    }

    public function withdraw(ClassGroup $group, User $student): void
    {
        $group->students()->newPivotStatement()
            ->where('class_group_id', $group->id)
            ->where('user_id', $student->id)
            ->update(['status' => 'withdrawn', 'withdrawn_at' => now()]);
    }

    /**
     * Add a weekly time and fill the calendar straight away - a coach who adds
     * a time expects to see the classes, not to wait for a nightly job.
     *
     * @return array{rule: ClassScheduleRule, created: int}
     */
    public function addRule(ClassGroup $group, array $data): array
    {
        $rule = $group->rules()->create([
            'weekday' => $data['weekday'],
            'start_time' => $data['start_time'],
            'duration_minutes' => $data['duration_minutes'],
            'starts_on' => $data['starts_on'],
            'ends_on' => $data['ends_on'] ?? null,
            'is_active' => true,
        ]);

        return [
            'rule' => $rule,
            'created' => $this->schedule->generate($group, (int) ($data['generate_weeks'] ?? 8)),
        ];
    }

    /**
     * The rule stops, and the classes it has already produced but not yet
     * taught are cancelled. Past sessions stay: they happened, and they are the
     * attendance record.
     *
     * @return int how many future classes were cancelled
     */
    public function removeRule(ClassGroup $group, ClassScheduleRule $rule): int
    {
        if ($rule->class_group_id !== $group->id) {
            throw new ClassroomException('That schedule belongs to another class.', 404);
        }

        $rule->update(['is_active' => false]);

        return ClassSession::where('schedule_rule_id', $rule->id)
            ->where('starts_at', '>', now())
            ->where('status', ClassSession::SCHEDULED)
            ->update(['status' => ClassSession::CANCELLED]);
    }
}
