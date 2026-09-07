<?php

namespace Tests\Feature\Classroom;

use App\Models\CefrLevel;
use App\Models\ClassGroup;
use App\Models\ClassSession;
use App\Models\School;
use App\Models\SchoolMember;
use App\Models\User;
use App\Services\Classroom\ClassScheduleService;
use App\Services\Classroom\SchoolService;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A school with a coach, two learners and a class, built the way the API would
 * build it rather than by inserting rows - so a test that passes here is a test
 * that the endpoints agree with the services.
 */
abstract class ClassroomTestCase extends TestCase
{
    use RefreshDatabase;

    protected School $school;

    protected User $owner;

    protected User $coach;

    protected User $student;

    protected User $otherStudent;

    /** Somebody at a different school entirely. */
    protected User $outsider;

    protected ClassGroup $group;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);

        $schools = app(SchoolService::class);

        $this->owner = $this->makeUser('Owner');
        $this->coach = $this->makeUser('Coach');
        $this->student = $this->makeUser('Student');
        $this->otherStudent = $this->makeUser('Other student');
        $this->outsider = $this->makeUser('Outsider');

        $this->school = $schools->create($this->owner, 'Edadras Institute');

        $schools->addMember($this->school, $this->coach, SchoolMember::COACH);
        $schools->addMember($this->school, $this->student, SchoolMember::STUDENT);
        $schools->addMember($this->school, $this->otherStudent, SchoolMember::STUDENT);

        $this->group = ClassGroup::create([
            'school_id' => $this->school->id,
            'coach_id' => $this->coach->id,
            'title' => 'Tuesday B1',
            'cefr_level_id' => CefrLevel::where('code', 'B1')->value('id'),
            'capacity' => 10,
            'timezone' => 'Asia/Tehran',
        ]);

        $this->group->students()->attach($this->student->id, [
            'status' => 'enrolled', 'enrolled_at' => now(),
        ]);
        $this->group->students()->attach($this->otherStudent->id, [
            'status' => 'enrolled', 'enrolled_at' => now(),
        ]);
    }

    protected function makeUser(string $name): User
    {
        return User::create([
            'name' => $name,
            'email' => str($name)->slug().'@example.test',
            'password' => bcrypt('secret-password'),
            'role' => 'learner',
            'status' => 'active',
            'timezone' => 'UTC',
        ]);
    }

    /** A session starting now, so the join window is open. */
    protected function makeSession(?\DateTimeInterface $startsAt = null): ClassSession
    {
        $starts = $startsAt ?? now();

        return ClassSession::create([
            'class_group_id' => $this->group->id,
            'coach_id' => $this->coach->id,
            'starts_at' => $starts,
            'ends_at' => (clone $starts)->modify('+90 minutes'),
            'status' => ClassSession::SCHEDULED,
            'room_name' => ClassScheduleService::roomName(),
        ]);
    }
}
