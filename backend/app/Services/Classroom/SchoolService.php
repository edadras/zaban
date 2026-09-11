<?php

namespace App\Services\Classroom;

use App\Models\ClassGroup;
use App\Models\ClassSession;
use App\Models\CoachStudent;
use App\Models\School;
use App\Models\SchoolMember;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Who belongs to a school, and in what capacity.
 *
 * Everyday membership never invents an account: a coach is added by email, and
 * only an account that already exists is attached. The one exception is
 * platform registration below - the site administrator deliberately hands a
 * school manager credentials so they can open the panel.
 *
 * And it never touches `users.role`. Being a coach is a fact about a person at
 * a school, not about the person; the platform role stays what it was, so an
 * account that teaches at one school and studies at another keeps both.
 */
class SchoolService
{
    public function create(User $owner, string $name, array $attributes = []): School
    {
        return DB::transaction(function () use ($owner, $name, $attributes) {
            $school = School::create([
                'name' => $name,
                'slug' => $this->slugFor($name),
                'owner_user_id' => $owner->id,
                'timezone' => $attributes['timezone'] ?? 'Asia/Tehran',
                'locale' => $attributes['locale'] ?? 'fa',
                'description' => $attributes['description'] ?? null,
            ]);

            SchoolMember::create([
                'school_id' => $school->id,
                'user_id' => $owner->id,
                'role' => SchoolMember::OWNER,
                'status' => 'active',
                'joined_at' => now(),
            ]);

            return $school;
        });
    }

    /**
     * Platform administrator registers a school and its manager together.
     *
     * Schools are not self-serve: only this path (and tests that call create
     * directly) should invent one. A missing manager account is created on
     * purpose here; day-to-day "add coach" still refuses unknown emails.
     *
     * @return array{school: School, owner: User, created_owner: bool}
     */
    public function registerForPlatform(
        string $schoolName,
        string $ownerName,
        string $ownerEmail,
        ?string $ownerPassword = null,
        array $attributes = [],
    ): array {
        $email = mb_strtolower(trim($ownerEmail));

        return DB::transaction(function () use ($schoolName, $ownerName, $email, $ownerPassword, $attributes) {
            $owner = User::where('email', $email)->first();
            $createdOwner = false;

            if ($owner === null) {
                if ($ownerPassword === null || $ownerPassword === '') {
                    throw new ClassroomException(
                        'A password is required when the manager does not have an account yet.',
                        422,
                    );
                }

                $owner = User::create([
                    'name' => $ownerName,
                    'email' => $email,
                    'password' => $ownerPassword,
                    'role' => 'learner',
                    'status' => 'active',
                    'timezone' => $attributes['timezone'] ?? 'Asia/Tehran',
                    'locale' => $attributes['locale'] ?? 'fa',
                    'email_verified_at' => now(),
                ]);
                $createdOwner = true;
            } else {
                if ($owner->status === 'suspended') {
                    throw new ClassroomException('That account is suspended.', 422);
                }

                $owner->fill([
                    'name' => $ownerName !== '' ? $ownerName : $owner->name,
                ]);

                if ($ownerPassword !== null && $ownerPassword !== '') {
                    $owner->password = $ownerPassword;
                }

                $owner->save();
            }

            $school = $this->create($owner, $schoolName, $attributes);

            return [
                'school' => $school,
                'owner' => $owner->fresh(),
                'created_owner' => $createdOwner,
            ];
        });
    }

    /** Attach an existing account to a school in a given part. */
    public function addMember(School $school, User $user, string $role, ?User $by = null, array $attributes = []): SchoolMember
    {
        if (! in_array($role, SchoolMember::ROLES, true)) {
            throw new ClassroomException("Unknown role: {$role}");
        }

        $member = SchoolMember::firstOrNew([
            'school_id' => $school->id,
            'user_id' => $user->id,
            'role' => $role,
        ]);

        $member->fill([
            'status' => 'active',
            'display_name' => $attributes['display_name'] ?? $member->display_name,
            'bio' => $attributes['bio'] ?? $member->bio,
            'invited_by' => $member->invited_by ?? $by?->id,
            'joined_at' => $member->joined_at ?? now(),
        ])->save();

        return $member;
    }

    public function removeMember(School $school, User $user, string $role): void
    {
        if ($role === SchoolMember::OWNER) {
            throw new ClassroomException('A school cannot be left without an owner.', 409);
        }

        /*
         * A class always has a coach - the column is not nullable - so letting
         * the last one leave would strand every class they teach at a person
         * the school no longer employs. The admin reassigns them first; the
         * error says how many, so they know what they are looking for.
         */
        if ($role === SchoolMember::COACH) {
            $teaching = ClassGroup::where('school_id', $school->id)
                ->where('coach_id', $user->id)
                ->where('is_active', true)
                ->count();

            if ($teaching > 0) {
                throw new ClassroomException(
                    "This coach still teaches {$teaching} active class(es). "
                        .'Move them to another coach, or close them, first.',
                    409,
                );
            }
        }

        SchoolMember::where('school_id', $school->id)
            ->where('user_id', $user->id)
            ->where('role', $role)
            ->delete();

        // A coach who leaves takes nothing with them: the learners they looked
        // after go back to the school's pool rather than to a coach who is gone.
        if ($role === SchoolMember::COACH) {
            CoachStudent::where('school_id', $school->id)
                ->where('coach_id', $user->id)
                ->whereNull('ended_at')
                ->update(['ended_at' => now()]);
        }
    }

    /**
     * The school putting a learner in a coach's care.
     *
     * Both have to be members of this school already. Without that check an
     * admin could attach anyone in the database to their coach, which is a
     * privacy hole shaped like a feature.
     */
    public function assignStudent(School $school, User $coach, User $student, ?User $by = null, ?string $note = null): CoachStudent
    {
        $this->assertMember($school, $coach, SchoolMember::COACH, 'That person is not a coach at this school.');
        $this->assertMember($school, $student, SchoolMember::STUDENT, 'That person is not a student at this school.');

        $link = CoachStudent::firstOrNew([
            'school_id' => $school->id,
            'coach_id' => $coach->id,
            'student_id' => $student->id,
        ]);

        $link->fill([
            'assigned_by' => $by?->id,
            'note' => $note ?? $link->note,
            'assigned_at' => $link->assigned_at ?? now(),
            'ended_at' => null,
        ])->save();

        return $link;
    }

    /**
     * Hand a class to a different coach.
     *
     * Sessions that have not been taught follow the class; the ones already
     * taught keep the coach who taught them, because they are the attendance
     * record of what actually happened.
     *
     * @return int how many future sessions moved with it
     */
    public function reassignClass(ClassGroup $group, User $coach): int
    {
        $this->assertMember(
            $group->school,
            $coach,
            SchoolMember::COACH,
            'That person is not a coach at this school.',
        );

        return DB::transaction(function () use ($group, $coach) {
            $group->update(['coach_id' => $coach->id]);

            return ClassSession::where('class_group_id', $group->id)
                ->where('status', ClassSession::SCHEDULED)
                ->where('starts_at', '>', now())
                ->update(['coach_id' => $coach->id]);
        });
    }

    public function unassignStudent(School $school, User $coach, User $student): void
    {
        CoachStudent::where('school_id', $school->id)
            ->where('coach_id', $coach->id)
            ->where('student_id', $student->id)
            ->whereNull('ended_at')
            ->update(['ended_at' => now()]);
    }

    /** Every school this person has any part in, with the parts they play. */
    public function membershipsFor(User $user): array
    {
        return SchoolMember::with('school')
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->get()
            ->groupBy('school_id')
            ->map(fn ($rows) => [
                'school' => $rows->first()->school,
                'roles' => $rows->pluck('role')->values()->all(),
            ])
            ->values()
            ->all();
    }

    public function isManager(School $school, User $user): bool
    {
        return SchoolMember::where('school_id', $school->id)
            ->where('user_id', $user->id)
            ->whereIn('role', SchoolMember::MANAGERS)
            ->where('status', 'active')
            ->exists();
    }

    public function isCoach(School $school, User $user): bool
    {
        return SchoolMember::where('school_id', $school->id)
            ->where('user_id', $user->id)
            ->where('role', SchoolMember::COACH)
            ->where('status', 'active')
            ->exists();
    }

    private function assertMember(School $school, User $user, string $role, string $message): void
    {
        $ok = SchoolMember::where('school_id', $school->id)
            ->where('user_id', $user->id)
            ->where('role', $role)
            ->where('status', 'active')
            ->exists();

        if (! $ok) {
            throw new ClassroomException($message);
        }
    }

    private function slugFor(string $name): string
    {
        // Persian names slug to nothing under Str::slug, so fall back to a
        // short random tail rather than to an empty unique key.
        $base = Str::slug($name) ?: 'school';
        $slug = $base;
        $n = 1;

        while (School::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$n);
        }

        return $slug;
    }
}
