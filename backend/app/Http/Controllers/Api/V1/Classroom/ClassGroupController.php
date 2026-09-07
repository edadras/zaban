<?php

namespace App\Http\Controllers\Api\V1\Classroom;

use App\Http\Controllers\Api\V1\ApiController;
use App\Models\ClassGroup;
use App\Models\ClassScheduleRule;
use App\Models\ClassSession;
use App\Models\School;
use App\Models\SchoolMember;
use App\Models\User;
use App\Services\Classroom\ClassroomException;
use App\Services\Classroom\ClassScheduleService;
use App\Services\Classroom\SchoolService;
use Illuminate\Http\Request;

/**
 * Classes and their timetables.
 *
 * A coach owns their own classes and a school's managers own all of them, which
 * is what `assertCanManage` decides. Everything else - who is on the roll, when
 * it meets - hangs off that one question.
 */
class ClassGroupController extends ApiController
{
    public function __construct(
        private readonly SchoolService $schools,
        private readonly ClassScheduleService $schedule,
    ) {}

    /** Classes this person teaches, or all of a school's if they manage it. */
    public function index(Request $request)
    {
        $user = $request->user();

        $groups = ClassGroup::with(['school', 'coach', 'level'])
            ->withCount(['students', 'sessions'])
            ->when($request->filled('school_id'), fn ($q) => $q->where('school_id', $request->integer('school_id')))
            ->where(function ($q) use ($user) {
                $q->where('coach_id', $user->id)
                    ->orWhereIn('school_id', SchoolMember::where('user_id', $user->id)
                        ->whereIn('role', SchoolMember::MANAGERS)
                        ->where('status', 'active')
                        ->pluck('school_id'));
            })
            ->orderByDesc('is_active')
            ->orderBy('title')
            ->get();

        return $this->ok($groups->map(fn (ClassGroup $g) => $this->present($g)));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'school_id' => ['required', 'integer', 'exists:schools,id'],
            'coach_id' => ['nullable', 'integer', 'exists:users,id'],
            'title' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
            'cefr_level_id' => ['nullable', 'integer', 'exists:cefr_levels,id'],
            'course_version_id' => ['nullable', 'integer', 'exists:course_versions,id'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:500'],
            'timezone' => ['nullable', 'string', 'max:64'],
        ]);

        $school = School::findOrFail($data['school_id']);
        $user = $request->user();

        // A manager may hand a class to any of the school's coaches. A coach
        // may only create their own, which is why the fallback is themselves
        // rather than whatever coach_id was posted.
        $coachId = $data['coach_id'] ?? $user->id;

        if ($coachId !== $user->id && ! $this->schools->isManager($school, $user)) {
            throw new ClassroomException('Only a school manager can assign a class to another coach.', 403);
        }
        if (! $this->schools->isManager($school, $user) && ! $this->schools->isCoach($school, $user)) {
            throw new ClassroomException('You do not teach at this school.', 403);
        }

        $group = ClassGroup::create([
            'school_id' => $school->id,
            'coach_id' => $coachId,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'cefr_level_id' => $data['cefr_level_id'] ?? null,
            'course_version_id' => $data['course_version_id'] ?? null,
            'capacity' => $data['capacity'] ?? 20,
            'timezone' => $data['timezone'] ?? $school->timezone,
        ]);

        return $this->created($this->present($group->fresh(['school', 'coach', 'level'])));
    }

    public function show(Request $request, ClassGroup $group)
    {
        $this->assertCanSee($request, $group);

        $group->load(['school', 'coach', 'level', 'rules', 'students.learnerProfile.cefrLevel']);

        return $this->ok($this->present($group) + [
            'rules' => $group->rules->map(fn (ClassScheduleRule $r) => $this->presentRule($r)),
            'students' => $group->students->map(fn (User $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'email' => $s->email,
                'cefr' => $s->learnerProfile?->cefrLevel?->code,
                'enrolled_at' => $s->pivot->enrolled_at,
            ]),
            'upcoming' => $group->sessions()
                ->where('starts_at', '>=', now()->subHours(2))
                ->orderBy('starts_at')->limit(20)->get()
                ->map(fn (ClassSession $s) => $this->presentSession($s)),
        ]);
    }

    public function update(Request $request, ClassGroup $group)
    {
        $this->assertCanManage($request, $group);

        $group->update($request->validate([
            'title' => ['sometimes', 'string', 'max:160'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'cefr_level_id' => ['sometimes', 'nullable', 'integer', 'exists:cefr_levels,id'],
            'course_version_id' => ['sometimes', 'nullable', 'integer', 'exists:course_versions,id'],
            'capacity' => ['sometimes', 'integer', 'min:1', 'max:500'],
            'timezone' => ['sometimes', 'string', 'max:64'],
            'is_active' => ['sometimes', 'boolean'],
        ]));

        return $this->ok($this->present($group->fresh(['school', 'coach', 'level'])));
    }

    public function destroy(Request $request, ClassGroup $group)
    {
        $this->assertCanManage($request, $group);
        $group->delete();

        return $this->ok(['deleted' => true]);
    }

    // ------------------------------------------------------------------ roll

    public function enrol(Request $request, ClassGroup $group)
    {
        $this->assertCanManage($request, $group);

        $data = $request->validate([
            'user_ids' => ['required', 'array', 'min:1'],
            'user_ids.*' => ['integer', 'exists:users,id'],
        ]);

        // Only learners the school already knows. Enrolment is not a way to
        // pull an arbitrary account into a school's roll.
        $eligible = SchoolMember::where('school_id', $group->school_id)
            ->where('role', SchoolMember::STUDENT)
            ->where('status', 'active')
            ->whereIn('user_id', $data['user_ids'])
            ->pluck('user_id');

        $rejected = collect($data['user_ids'])->diff($eligible)->values();

        $room = $group->capacity - $group->students()->count();
        if ($eligible->count() > $room) {
            throw new ClassroomException(
                "This class has room for {$room} more.",
            );
        }

        foreach ($eligible as $id) {
            $group->students()->syncWithoutDetaching([
                $id => ['status' => 'enrolled', 'enrolled_at' => now(), 'withdrawn_at' => null],
            ]);
        }

        return $this->ok([
            'enrolled' => $eligible->values(),
            'rejected' => $rejected,
        ]);
    }

    public function withdraw(Request $request, ClassGroup $group, User $student)
    {
        $this->assertCanManage($request, $group);

        $group->students()->newPivotStatement()
            ->where('class_group_id', $group->id)
            ->where('user_id', $student->id)
            ->update(['status' => 'withdrawn', 'withdrawn_at' => now()]);

        return $this->ok(['withdrawn' => true]);
    }

    // -------------------------------------------------------------- schedule

    public function addRule(Request $request, ClassGroup $group)
    {
        $this->assertCanManage($request, $group);

        $data = $request->validate([
            'weekday' => ['required', 'integer', 'min:0', 'max:6'],
            'start_time' => ['required', 'date_format:H:i'],
            'duration_minutes' => ['required', 'integer', 'min:10', 'max:480'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['nullable', 'date', 'after:starts_on'],
            'generate_weeks' => ['nullable', 'integer', 'min:1', 'max:52'],
        ]);

        $rule = $group->rules()->create($data + ['is_active' => true]);

        // Fill the calendar straight away: a coach who adds a time expects to
        // see the classes, not to wait for a nightly job.
        $created = $this->schedule->generate($group, (int) ($data['generate_weeks'] ?? 8));

        return $this->created($this->presentRule($rule) + ['sessions_created' => $created]);
    }

    public function removeRule(Request $request, ClassGroup $group, ClassScheduleRule $rule)
    {
        $this->assertCanManage($request, $group);

        if ($rule->class_group_id !== $group->id) {
            throw new ClassroomException('That schedule belongs to another class.', 404);
        }

        /*
         * The rule stops, and the classes it has already produced but not yet
         * taught are cancelled. Past sessions stay: they happened, and they are
         * the attendance record.
         */
        $rule->update(['is_active' => false]);
        $cancelled = ClassSession::where('schedule_rule_id', $rule->id)
            ->where('starts_at', '>', now())
            ->where('status', ClassSession::SCHEDULED)
            ->update(['status' => ClassSession::CANCELLED]);

        return $this->ok(['cancelled_sessions' => $cancelled]);
    }

    public function generate(Request $request, ClassGroup $group)
    {
        $this->assertCanManage($request, $group);

        $created = $this->schedule->generate(
            $group,
            (int) $request->integer('weeks', 8),
        );

        return $this->ok(['created' => $created]);
    }

    // ------------------------------------------------------------ authorising

    private function assertCanSee(Request $request, ClassGroup $group): void
    {
        $user = $request->user();

        if ($group->coach_id === $user->id) {
            return;
        }
        if ($this->schools->isManager($group->school, $user)) {
            return;
        }
        if ($group->students()->whereKey($user->id)->exists()) {
            return;
        }

        throw new ClassroomException('This class is not yours.', 403);
    }

    private function assertCanManage(Request $request, ClassGroup $group): void
    {
        $user = $request->user();

        if ($group->coach_id === $user->id || $this->schools->isManager($group->school, $user)) {
            return;
        }

        throw new ClassroomException('Only this class\'s coach or a school manager can change it.', 403);
    }

    private function present(ClassGroup $group): array
    {
        return [
            'id' => $group->id,
            'school_id' => $group->school_id,
            'school' => $group->school?->name,
            'coach_id' => $group->coach_id,
            'coach' => $group->coach?->name,
            'title' => $group->title,
            'description' => $group->description,
            'cefr' => $group->level?->code,
            'capacity' => $group->capacity,
            'timezone' => $group->timezone,
            'is_active' => $group->is_active,
            'student_count' => $group->students_count ?? null,
            'session_count' => $group->sessions_count ?? null,
        ];
    }

    private function presentRule(ClassScheduleRule $rule): array
    {
        return [
            'id' => $rule->id,
            'weekday' => $rule->weekday,
            'start_time' => substr((string) $rule->start_time, 0, 5),
            'duration_minutes' => $rule->duration_minutes,
            'starts_on' => $rule->starts_on?->toDateString(),
            'ends_on' => $rule->ends_on?->toDateString(),
            'is_active' => $rule->is_active,
        ];
    }

    private function presentSession(ClassSession $session): array
    {
        return [
            'id' => $session->id,
            'title' => $session->title,
            'starts_at' => $session->starts_at?->toIso8601String(),
            'ends_at' => $session->ends_at?->toIso8601String(),
            'status' => $session->status,
        ];
    }
}
