<?php

namespace Tests\Feature\Panel;

use App\Models\User;

/**
 * The front door.
 *
 * The interesting cases are the refusals: the panel is where a school's roll
 * lives, so who cannot open it matters more than who can.
 */
class PanelAccessTest extends PanelTestCase
{
    public function test_the_panel_is_closed_to_a_stranger(): void
    {
        $this->get('/panel')->assertRedirect(route('panel.login'));
    }

    public function test_a_school_owner_signs_in(): void
    {
        $this->post(route('panel.login.attempt'), [
            'email' => $this->owner->email,
            'password' => 'secret-password',
        ])->assertRedirect(route('panel.home'));

        $this->assertAuthenticatedAs($this->owner);
        $this->get('/panel')->assertOk()->assertSee('میز کار');
    }

    public function test_a_coach_signs_in(): void
    {
        $this->post(route('panel.login.attempt'), [
            'email' => $this->coach->email,
            'password' => 'secret-password',
        ])->assertRedirect(route('panel.home'));

        $this->assertAuthenticatedAs($this->coach);
    }

    /**
     * A learner belongs in the app.
     *
     * And is signed straight back out rather than left holding a session that
     * every page then has to refuse individually.
     */
    public function test_a_learner_is_turned_away_and_not_left_signed_in(): void
    {
        $this->post(route('panel.login.attempt'), [
            'email' => $this->student->email,
            'password' => 'secret-password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_a_wrong_password_is_refused(): void
    {
        $this->post(route('panel.login.attempt'), [
            'email' => $this->owner->email,
            'password' => 'not-the-password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    /** A platform administrator with no school still has a panel. */
    public function test_a_platform_administrator_gets_in(): void
    {
        $admin = $this->makeUser('Platform admin');
        $admin->update(['role' => 'admin']);

        $this->actingAs($admin)->get('/panel')->assertOk();
        $this->actingAs($admin)->get(route('panel.platform.overview'))->assertOk();
    }

    /** But a coach is not a platform administrator. */
    public function test_the_platform_section_is_not_a_schools_to_open(): void
    {
        $this->actingAs($this->coach)->get(route('panel.platform.overview'))->assertForbidden();
        $this->actingAs($this->coach)->get(route('panel.platform.schools'))->assertForbidden();
        $this->actingAs($this->coach)->get(route('panel.platform.users'))->assertForbidden();
    }

    /** And a platform administrator is not automatically a school's manager. */
    public function test_a_platform_administrator_cannot_read_a_schools_roll(): void
    {
        $admin = $this->makeUser('Platform admin');
        $admin->update(['role' => 'admin']);

        $this->actingAs($admin)
            ->get(route('panel.schools.people', $this->school))
            ->assertForbidden();
    }

    public function test_signing_out_ends_the_session(): void
    {
        $this->actingAs($this->owner)
            ->post(route('panel.logout'))
            ->assertRedirect(route('panel.login'));

        $this->assertGuest();
    }

    public function test_a_suspended_looking_account_still_needs_the_right_password(): void
    {
        User::where('id', $this->owner->id)->update(['password' => bcrypt('changed')]);

        $this->post(route('panel.login.attempt'), [
            'email' => $this->owner->email,
            'password' => 'secret-password',
        ])->assertSessionHasErrors('email');
    }
}
