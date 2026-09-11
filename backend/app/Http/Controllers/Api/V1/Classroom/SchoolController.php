<?php

namespace App\Http\Controllers\Api\V1\Classroom;

use App\Http\Controllers\Api\V1\ApiController;
use App\Models\CoachStudent;
use App\Models\School;
use App\Models\SchoolMember;
use App\Models\User;
use App\Services\Classroom\ClassroomException;
use App\Services\Classroom\SchoolService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The school as its owner and admins see it: a roll of coaches, a roll of
 * learners, and the lines drawn between them.
 *
 * Every route here is guarded twice - by the route's admin middleware and by
 * `authorise()` below, which asks whether this particular person manages this
 * particular school. The first stops a learner reaching the endpoint; the
 * second stops one school's admin reaching another's.
 */
class SchoolController extends ApiController
{
    public function __construct(private readonly SchoolService $schools) {}

    /** The schools this person has a part in. */
    public function index(Request $request)
    {
        return $this->ok(collect($this->schools->membershipsFor($request->user()))
            ->map(fn (array $m) => [
                'id' => $m['school']->id,
                'name' => $m['school']->name,
                'slug' => $m['school']->slug,
                'timezone' => $m['school']->timezone,
                'roles' => $m['roles'],
                'is_active' => $m['school']->is_active,
            ])->values());
    }

    public function store(Request $request)
    {
        // Schools are not self-serve. Only a platform administrator may register
        // one, and they must name the manager who will run it afterwards.
        if ($request->user()->role !== 'admin') {
            throw new ClassroomException('Only a platform administrator may register a school.', 403);
        }

        $email = mb_strtolower((string) $request->input('owner_email'));
        $ownerExists = User::where('email', $email)->exists();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'timezone' => ['nullable', 'string', 'max:64'],
            'description' => ['nullable', 'string', 'max:2000'],
            'owner_name' => ['required', 'string', 'max:120'],
            'owner_email' => ['required', 'email', 'max:190'],
            'owner_password' => [
                Rule::requiredIf(! $ownerExists),
                'nullable',
                'string',
                'min:8',
            ],
        ]);

        $result = $this->schools->registerForPlatform(
            $data['name'],
            $data['owner_name'],
            $data['owner_email'],
            $data['owner_password'] ?? null,
            $data,
        );

        return $this->created([
            ...$this->present($result['school']),
            'owner' => [
                'id' => $result['owner']->id,
                'name' => $result['owner']->name,
                'email' => $result['owner']->email,
                'created' => $result['created_owner'],
            ],
        ]);
    }

    public function show(Request $request, School $school)
    {
        $this->authorise($request, $school);

        $school->loadCount([
            'members as coach_count' => fn ($q) => $q->where('role', SchoolMember::COACH),
            'members as student_count' => fn ($q) => $q->where('role', SchoolMember::STUDENT),
            'classGroups as class_count' => fn ($q) => $q->where('is_active', true),
        ]);

        return $this->ok($this->present($school));
    }

    public function update(Request $request, School $school)
    {
        $this->authorise($request, $school);

        $school->update($request->validate([
            'name' => ['sometimes', 'string', 'max:160'],
            'timezone' => ['sometimes', 'string', 'max:64'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
        ]));

        return $this->ok($this->present($school->fresh()));
    }

    /** Coaches, learners, or both. */
    public function members(Request $request, School $school)
    {
        $this->authorise($request, $school);

        $members = SchoolMember::with('user.learnerProfile.cefrLevel')
            ->where('school_id', $school->id)
            ->when($request->filled('role'), fn ($q) => $q->where('role', $request->string('role')))
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = '%'.$request->string('q').'%';
                $q->whereHas('user', fn ($u) => $u->where('name', 'like', $term)->orWhere('email', 'like', $term));
            })
            ->orderBy('role')
            ->paginate(min(100, $request->integer('per_page', 50)));

        return $this->ok($members->through(fn (SchoolMember $m) => $this->presentMember($m)));
    }

    /**
     * Add someone who already has an account.
     *
     * A missing account is a 404 with the email echoed back, so the admin
     * screen can offer to invite them instead of the server quietly creating a
     * user nobody asked for.
     */
    public function addMember(Request $request, School $school)
    {
        $this->authorise($request, $school);

        $data = $request->validate([
            'email' => ['required', 'email'],
            'role' => ['required', 'string', 'in:admin,coach,student'],
            'display_name' => ['nullable', 'string', 'max:120'],
            'bio' => ['nullable', 'string', 'max:1000'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if ($user === null) {
            return $this->fail(
                'no_such_account',
                'Nobody is registered with that email address yet.',
                404,
                ['email' => $data['email']],
            );
        }

        $member = $this->schools->addMember(
            $school, $user, $data['role'], $request->user(), $data,
        );

        return $this->created($this->presentMember($member->load('user')));
    }

    public function removeMember(Request $request, School $school, SchoolMember $member)
    {
        $this->authorise($request, $school);
        $this->assertBelongs($school, $member);

        $this->schools->removeMember($school, $member->user, $member->role);

        return $this->ok(['removed' => true]);
    }

    /** Learners in this coach's care. */
    public function coachStudents(Request $request, School $school, User $coach)
    {
        $this->authorise($request, $school);

        $links = CoachStudent::with('student.learnerProfile.cefrLevel')
            ->where('school_id', $school->id)
            ->where('coach_id', $coach->id)
            ->whereNull('ended_at')
            ->get();

        return $this->ok($links->map(fn (CoachStudent $l) => [
            'id' => $l->id,
            'student_id' => $l->student_id,
            'name' => $l->student?->name,
            'email' => $l->student?->email,
            'cefr' => $l->student?->learnerProfile?->cefrLevel?->code,
            'note' => $l->note,
            'assigned_at' => $l->assigned_at?->toIso8601String(),
        ]));
    }

    public function assignStudent(Request $request, School $school, User $coach)
    {
        $this->authorise($request, $school);

        $data = $request->validate([
            'student_id' => ['required', 'integer', 'exists:users,id'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $link = $this->schools->assignStudent(
            $school,
            $coach,
            User::findOrFail($data['student_id']),
            $request->user(),
            $data['note'] ?? null,
        );

        return $this->created(['id' => $link->id, 'assigned' => true]);
    }

    public function unassignStudent(Request $request, School $school, User $coach, User $student)
    {
        $this->authorise($request, $school);

        $this->schools->unassignStudent($school, $coach, $student);

        return $this->ok(['unassigned' => true]);
    }

    private function authorise(Request $request, School $school): void
    {
        if (! $this->schools->isManager($school, $request->user())) {
            throw new ClassroomException('You do not manage this school.', 403);
        }
    }

    private function assertBelongs(School $school, SchoolMember $member): void
    {
        if ($member->school_id !== $school->id) {
            throw new ClassroomException('That member is not at this school.', 404);
        }
    }

    private function present(School $school): array
    {
        return [
            'id' => $school->id,
            'name' => $school->name,
            'slug' => $school->slug,
            'timezone' => $school->timezone,
            'locale' => $school->locale,
            'description' => $school->description,
            'is_active' => $school->is_active,
            'coach_count' => $school->coach_count ?? null,
            'student_count' => $school->student_count ?? null,
            'class_count' => $school->class_count ?? null,
        ];
    }

    private function presentMember(SchoolMember $member): array
    {
        return [
            'id' => $member->id,
            'user_id' => $member->user_id,
            'name' => $member->display_name ?: $member->user?->name,
            'email' => $member->user?->email,
            'role' => $member->role,
            'status' => $member->status,
            'bio' => $member->bio,
            'cefr' => $member->user?->learnerProfile?->cefrLevel?->code,
            'joined_at' => $member->joined_at?->toIso8601String(),
        ];
    }
}
