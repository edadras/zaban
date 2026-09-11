<?php

namespace App\Http\Controllers\Panel;

use App\Models\ClassGroup;
use App\Models\CoachStudent;
use App\Models\School;
use App\Models\SchoolMember;
use App\Models\User;
use App\Services\Classroom\SchoolService;
use App\Support\PanelAccess;
use Illuminate\Http\Request;

/**
 * The school as an administrator sees it: the building, the people in it, and
 * who looks after whom.
 */
class SchoolController extends PanelController
{
    public function __construct(PanelAccess $access, private readonly SchoolService $schools)
    {
        parent::__construct($access);
    }

    public function index()
    {
        $user = $this->me();

        return view('panel.schools.index', [
            'managed' => $this->access->managedSchools($user)->load('owner')->loadCount('classGroups'),
            'coaching' => $this->access->coachingSchools($user),
            'isPlatformAdmin' => $user->role === 'admin',
        ]);
    }

    public function show(School $school)
    {
        $this->allow($this->access->canSeeSchool($this->me(), $school));

        $isManager = $this->access->canManageSchool($this->me(), $school);

        $groups = ClassGroup::with(['coach', 'level'])
            ->withCount(['students', 'sessions'])
            ->where('school_id', $school->id)
            ->when(! $isManager, fn ($q) => $q->where('coach_id', $this->me()->id))
            ->orderByDesc('is_active')->orderBy('title')
            ->get();

        return view('panel.schools.show', [
            'school' => $school,
            'isManager' => $isManager,
            'groups' => $groups,
            'coachCount' => $school->coaches()->where('status', 'active')->count(),
            'studentCount' => $school->students()->where('status', 'active')->count(),
        ]);
    }

    public function update(Request $request, School $school)
    {
        $this->allow($this->access->canManageSchool($this->me(), $school));

        $school->update($request->validate([
            'name' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
            'timezone' => ['nullable', 'string', 'max:64'],
            'locale' => ['nullable', 'string', 'max:8'],
        ]));

        return redirect()->route('panel.schools.show', $school)->with('status', 'ذخیره شد.');
    }

    // ---------------------------------------------------------------- people

    public function people(Request $request, School $school)
    {
        $this->allow($this->access->canManageSchool($this->me(), $school));

        $search = trim((string) $request->query('q', ''));

        $members = SchoolMember::with('user')
            ->where('school_id', $school->id)
            ->where('status', 'active')
            ->when($search !== '', fn ($q) => $q->whereHas(
                'user',
                fn ($u) => $u->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")
            ))
            ->get()
            ->sortBy(fn (SchoolMember $m) => $m->user?->name ?? '');

        return view('panel.schools.people', [
            'school' => $school,
            'search' => $search,
            'managers' => $members->whereIn('role', SchoolMember::MANAGERS)->values(),
            'coaches' => $members->where('role', SchoolMember::COACH)->values(),
            'students' => $members->where('role', SchoolMember::STUDENT)->values(),
        ]);
    }

    public function addMember(Request $request, School $school)
    {
        $this->allow($this->access->canManageSchool($this->me(), $school));

        $data = $request->validate([
            'email' => ['required', 'email'],
            'role' => ['required', 'string', 'in:admin,coach,student'],
            'display_name' => ['nullable', 'string', 'max:120'],
        ]);

        $user = User::where('email', mb_strtolower($data['email']))->first();

        // Never invent an account. An administrator who mistypes an address
        // should be told, not handed a user nobody can explain later.
        if ($user === null) {
            return back()->withInput()->withErrors([
                'email' => 'حسابی با این ایمیل وجود ندارد. ابتدا از او بخواهید در اپلیکیشن ثبت‌نام کند.',
            ]);
        }

        return $this->attempt(
            fn () => $this->schools->addMember($school, $user, $data['role'], $this->me(), $data),
            route('panel.schools.people', $school),
            'به آموزشگاه افزوده شد.',
        );
    }

    public function removeMember(School $school, SchoolMember $member)
    {
        $this->allow($this->access->canManageSchool($this->me(), $school));
        abort_unless($member->school_id === $school->id, 404);

        return $this->attempt(
            fn () => $this->schools->removeMember($school, $member->user, $member->role),
            route('panel.schools.people', $school),
            'از آموزشگاه حذف شد.',
        );
    }

    // ----------------------------------------------------- coach and learners

    public function coach(School $school, User $coach)
    {
        $this->allow($this->access->canManageSchool($this->me(), $school));
        abort_unless($this->schools->isCoach($school, $coach), 404);

        $assigned = CoachStudent::with('student')
            ->where('school_id', $school->id)
            ->where('coach_id', $coach->id)
            ->whereNull('ended_at')
            ->get();

        $roll = SchoolMember::with('user')
            ->where('school_id', $school->id)
            ->where('role', SchoolMember::STUDENT)
            ->where('status', 'active')
            ->get()
            ->reject(fn (SchoolMember $m) => $assigned->contains('student_id', $m->user_id))
            ->sortBy(fn (SchoolMember $m) => $m->user?->name ?? '')
            ->values();

        return view('panel.schools.coach', [
            'school' => $school,
            'coach' => $coach,
            'assigned' => $assigned,
            'available' => $roll,
            'groups' => ClassGroup::withCount('students')
                ->where('school_id', $school->id)->where('coach_id', $coach->id)->get(),
        ]);
    }

    public function assignStudent(Request $request, School $school, User $coach)
    {
        $this->allow($this->access->canManageSchool($this->me(), $school));

        $data = $request->validate([
            'student_id' => ['required', 'integer', 'exists:users,id'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $student = User::findOrFail($data['student_id']);

        return $this->attempt(
            fn () => $this->schools->assignStudent($school, $coach, $student, $this->me(), $data['note'] ?? null),
            route('panel.schools.coach', [$school, $coach]),
            'زبان‌آموز به مربی سپرده شد.',
        );
    }

    public function unassignStudent(School $school, User $coach, User $student)
    {
        $this->allow($this->access->canManageSchool($this->me(), $school));

        $this->schools->unassignStudent($school, $coach, $student);

        return redirect()->route('panel.schools.coach', [$school, $coach])
            ->with('status', 'ارتباط پایان یافت.');
    }
}
