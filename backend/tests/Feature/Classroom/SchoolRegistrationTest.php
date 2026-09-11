<?php

namespace Tests\Feature\Classroom;

use App\Models\School;
use App\Models\User;
use App\Services\Classroom\ClassroomException;
use App\Services\Classroom\SchoolService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Registering a school must never cost somebody their account.
 *
 * The path exists so a platform administrator can open a school and hand its
 * manager credentials. What it must not become is a way to reach an account
 * that already exists: registering against an address that is already somebody
 * else's used to rename that person and, if a password was typed, reset it -
 * so a mistyped email locked a learner out of the course they had paid for and
 * quietly made a stranger the owner of a school.
 *
 * The rule now is narrow and easy to keep: an existing account is attached, and
 * nothing about it is changed.
 */
class SchoolRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private SchoolService $schools;

    protected function setUp(): void
    {
        parent::setUp();
        $this->schools = app(SchoolService::class);
    }

    private function learner(string $name, string $email, string $password): User
    {
        return User::factory()->create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
            'role' => 'learner',
            'status' => 'active',
        ]);
    }

    public function test_a_manager_with_no_account_yet_gets_one(): void
    {
        $result = $this->schools->registerForPlatform(
            'آموزشگاه نو',
            'مدیر نو',
            'New.Manager@Example.test',
            'SecretPass123!',
        );

        $this->assertTrue($result['created_owner']);
        // The address is folded to lower case, so signing in works however it
        // was typed into the form.
        $this->assertSame('new.manager@example.test', $result['owner']->email);
        $this->assertSame('مدیر نو', $result['owner']->name);
        $this->assertTrue(Auth::attempt([
            'email' => 'new.manager@example.test',
            'password' => 'SecretPass123!',
        ]));
    }

    public function test_an_existing_account_keeps_its_password(): void
    {
        $learner = $this->learner('Sara', 'sara@example.test', 'HerOwnPassword1!');

        $this->schools->registerForPlatform('مدرسهٔ الف', 'Someone Else', 'sara@example.test');

        $this->assertTrue(
            Auth::attempt(['email' => 'sara@example.test', 'password' => 'HerOwnPassword1!']),
            'the account she already had must still open with the password she already had',
        );
        Auth::logout();
        $this->assertSame($learner->password, $learner->fresh()->password);
    }

    public function test_an_existing_account_keeps_its_name(): void
    {
        $learner = $this->learner('Sara', 'sara@example.test', 'HerOwnPassword1!');

        $this->schools->registerForPlatform('مدرسهٔ الف', 'Someone Else', 'sara@example.test');

        $this->assertSame('Sara', $learner->fresh()->name);
    }

    public function test_a_password_for_an_address_that_already_exists_is_refused(): void
    {
        $this->learner('Sara', 'sara@example.test', 'HerOwnPassword1!');

        $this->expectException(ClassroomException::class);
        $this->expectExceptionMessage('That email already belongs to an account');

        $this->schools->registerForPlatform(
            'مدرسهٔ ب',
            'Someone Else',
            'sara@example.test',
            'ADifferentPassword1!',
        );
    }

    public function test_a_refused_registration_creates_no_school_at_all(): void
    {
        $this->learner('Sara', 'sara@example.test', 'HerOwnPassword1!');

        try {
            $this->schools->registerForPlatform(
                'مدرسهٔ ب',
                'Someone Else',
                'sara@example.test',
                'ADifferentPassword1!',
            );
        } catch (ClassroomException) {
            // The whole thing runs in a transaction; a refusal half way through
            // must not leave a school standing with nobody meant to own it.
        }

        $this->assertDatabaseMissing('schools', ['name' => 'مدرسهٔ ب']);
    }

    public function test_an_existing_account_is_handed_the_school(): void
    {
        $coach = $this->learner('Reza', 'reza@example.test', 'HisOwnPassword1!');

        $result = $this->schools->registerForPlatform('مدرسهٔ ج', 'Reza', 'reza@example.test');

        $this->assertFalse($result['created_owner']);
        $this->assertSame($coach->id, $result['owner']->id);
        $this->assertDatabaseHas('schools', [
            'name' => 'مدرسهٔ ج',
            'owner_user_id' => $coach->id,
        ]);
        $this->assertDatabaseHas('school_members', [
            'user_id' => $coach->id,
            'role' => 'owner',
            'status' => 'active',
        ]);
    }

    public function test_the_platform_role_of_an_existing_account_is_left_alone(): void
    {
        $admin = $this->learner('Admin', 'admin@example.test', 'AdminPassword1!');
        $admin->update(['role' => 'admin']);

        $this->schools->registerForPlatform('مدرسهٔ د', 'Admin', 'admin@example.test');

        // Owning a school is a fact about a person at a school. It must not
        // quietly demote a platform administrator to a learner.
        $this->assertSame('admin', $admin->fresh()->role);
    }

    public function test_a_suspended_account_is_refused(): void
    {
        $suspended = $this->learner('Gone', 'gone@example.test', 'Password1!');
        $suspended->update(['status' => 'suspended']);

        $this->expectException(ClassroomException::class);
        $this->expectExceptionMessage('suspended');

        $this->schools->registerForPlatform('مدرسهٔ ه', 'Gone', 'gone@example.test');
    }

    public function test_a_new_manager_without_a_password_is_refused(): void
    {
        $this->expectException(ClassroomException::class);
        $this->expectExceptionMessage('A password is required');

        $this->schools->registerForPlatform('مدرسهٔ و', 'Nobody', 'nobody@example.test');

        $this->assertDatabaseMissing('schools', ['name' => 'مدرسهٔ و']);
    }

    // ------------------------------------------------------------ the panel

    public function test_the_panel_refuses_a_password_for_an_address_that_exists(): void
    {
        $learner = $this->learner('Sara', 'sara@example.test', 'HerOwnPassword1!');
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);

        $this->actingAs($admin)
            ->post(route('panel.platform.schools.store'), [
                'name' => 'مدرسهٔ ز',
                'owner_name' => 'Someone Else',
                'owner_email' => 'sara@example.test',
                'owner_password' => 'ADifferentPassword1!',
                'owner_password_confirmation' => 'ADifferentPassword1!',
            ])
            ->assertSessionHasErrors('owner_email');

        $this->assertDatabaseMissing('schools', ['name' => 'مدرسهٔ ز']);
        $this->assertSame('Sara', $learner->fresh()->name);
        $this->assertTrue(
            Auth::attempt(['email' => 'sara@example.test', 'password' => 'HerOwnPassword1!']),
        );
    }

    public function test_the_api_refuses_a_password_for_an_address_that_exists(): void
    {
        $learner = $this->learner('Sara', 'sara@example.test', 'HerOwnPassword1!');
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/schools', [
                'name' => 'مدرسهٔ ح',
                'owner_name' => 'Someone Else',
                'owner_email' => 'sara@example.test',
                'owner_password' => 'ADifferentPassword1!',
            ])
            ->assertStatus(422);

        $this->assertDatabaseMissing('schools', ['name' => 'مدرسهٔ ح']);
        $this->assertSame($learner->password, $learner->fresh()->password);
    }

    public function test_the_api_hands_the_school_to_an_existing_account(): void
    {
        $coach = $this->learner('Reza', 'reza@example.test', 'HisOwnPassword1!');
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/schools', [
                'name' => 'مدرسهٔ ط',
                'owner_name' => 'Reza',
                'owner_email' => 'reza@example.test',
            ])
            ->assertCreated()
            ->assertJsonPath('data.owner.created', false)
            ->assertJsonPath('data.owner.id', $coach->id);
    }

    public function test_only_a_platform_administrator_may_register_a_school(): void
    {
        $coach = $this->learner('Coach', 'coach@example.test', 'Password1!');

        $this->actingAs($coach, 'sanctum')
            ->postJson('/api/v1/schools', [
                'name' => 'مدرسهٔ ی',
                'owner_name' => 'Coach',
                'owner_email' => 'coach@example.test',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('schools', ['name' => 'مدرسهٔ ی']);
        $this->assertSame(0, School::where('name', 'مدرسهٔ ی')->count());
    }
}
