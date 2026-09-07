<?php

namespace App\Support;

use App\Models\ClassGroup;
use App\Models\ClassSession;
use App\Models\School;
use App\Models\SchoolMember;
use App\Models\User;
use App\Services\Classroom\SchoolService;
use Illuminate\Database\Eloquent\Collection;

/**
 * Who may open which door in the web panel.
 *
 * The rules are the API's rules, said once. A platform administrator is not
 * automatically a school's manager here either: the panel's platform section
 * and its school section are different rooms, and being let into one says
 * nothing about the other. Anything else would let an editor read the roll of
 * every school on the installation.
 */
class PanelAccess
{
    public function __construct(private readonly SchoolService $schools) {}

    /** Somebody with any reason at all to see the panel. */
    public function isPanelUser(User $user): bool
    {
        return $user->isAdmin() || $this->staffMemberships($user)->isNotEmpty();
    }

    /** @return Collection<int, SchoolMember> owner / admin / coach rows */
    public function staffMemberships(User $user): Collection
    {
        return SchoolMember::with('school')
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->whereIn('role', [SchoolMember::OWNER, SchoolMember::ADMIN, SchoolMember::COACH])
            ->get()
            ->filter(fn (SchoolMember $m) => $m->school !== null)
            ->values();
    }

    /** @return Collection<int, School> the schools this person runs */
    public function managedSchools(User $user): Collection
    {
        return $this->schoolsWhere($user, SchoolMember::MANAGERS);
    }

    /** @return Collection<int, School> the schools this person teaches at */
    public function coachingSchools(User $user): Collection
    {
        return $this->schoolsWhere($user, [SchoolMember::COACH]);
    }

    /**
     * An Eloquent collection rather than a plucked one, so the views can go on
     * loading counts and relations off it.
     *
     * @param  list<string>  $roles
     * @return Collection<int, School>
     */
    private function schoolsWhere(User $user, array $roles): Collection
    {
        return School::whereIn('id', SchoolMember::where('user_id', $user->id)
            ->where('status', 'active')
            ->whereIn('role', $roles)
            ->select('school_id'))
            ->orderBy('name')
            ->get();
    }

    public function canManageSchool(User $user, School $school): bool
    {
        return $this->schools->isManager($school, $user);
    }

    public function canSeeSchool(User $user, School $school): bool
    {
        return $this->schools->isManager($school, $user) || $this->schools->isCoach($school, $user);
    }

    public function canSeeGroup(User $user, ClassGroup $group): bool
    {
        return $group->coach_id === $user->id
            || $this->schools->isManager($group->school, $user);
    }

    public function canManageGroup(User $user, ClassGroup $group): bool
    {
        return $this->canSeeGroup($user, $group);
    }

    /** Running a class is the coach's job, and the school manager's fallback. */
    public function canRunSession(User $user, ClassSession $session): bool
    {
        return $session->coach_id === $user->id
            || $this->schools->isManager($session->group->school, $user);
    }
}
