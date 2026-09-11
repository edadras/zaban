<?php

namespace Tests\Feature\Panel;

use App\Models\ClassMaterial;

/**
 * Every page of the panel renders.
 *
 * A Blade template that does not compile is invisible until somebody opens it,
 * and a coach opening it is the wrong moment to find out. This walks the whole
 * panel once so a broken directive fails here instead.
 */
class PanelPagesTest extends PanelTestCase
{
    public function test_every_page_a_school_manager_can_reach_renders(): void
    {
        $session = $this->makeSession();
        ClassMaterial::create([
            'class_session_id' => $session->id,
            'uploaded_by' => $this->coach->id,
            'kind' => 'text',
            'title' => 'On the shelf',
            'body' => 'Some text.',
        ]);

        $this->actingAs($this->owner)->post(route('panel.sessions.start', $session));
        $this->actingAs($this->owner)->post(route('panel.notifications.read-all'));

        $pages = [
            route('panel.home'),
            route('panel.schools.index'),
            route('panel.schools.show', $this->school),
            route('panel.schools.people', $this->school),
            route('panel.schools.coach', [$this->school, $this->coach]),
            route('panel.classes.index'),
            route('panel.classes.show', $this->group),
            route('panel.sessions.index'),
            route('panel.sessions.show', $session),
            route('panel.sessions.show', $session).'?lesson=word',
            route('panel.sessions.attendance', $session),
            route('panel.sessions.recording', $session),
            route('panel.sessions.room', $session),
            route('panel.notifications'),
        ];

        foreach ($pages as $page) {
            $this->actingAs($this->owner)->get($page)->assertOk();
        }
    }

    public function test_every_platform_page_renders(): void
    {
        $admin = $this->makeUser('Platform admin');
        $admin->update(['role' => 'admin']);

        foreach ([
            route('panel.platform.overview'),
            route('panel.platform.schools'),
            route('panel.platform.users'),
            route('panel.platform.users').'?q=coach&role=learner&status=active',
            route('panel.platform.audit'),
        ] as $page) {
            $this->actingAs($admin)->get($page)->assertOk();
        }
    }

    public function test_the_login_page_renders(): void
    {
        $this->get(route('panel.login'))->assertOk()->assertSee('پنل آموزشگاه');
    }

    public function test_a_platform_admin_changes_a_role_but_not_their_own(): void
    {
        $admin = $this->makeUser('Platform admin');
        $admin->update(['role' => 'admin']);

        $this->actingAs($admin)
            ->patch(route('panel.platform.users.update', $this->student), [
                'role' => 'editor',
                'status' => 'active',
            ])
            ->assertSessionHas('status');

        $this->assertSame('editor', $this->student->fresh()->role);

        $this->actingAs($admin)
            ->patch(route('panel.platform.users.update', $admin), [
                'role' => 'learner',
                'status' => 'active',
            ])
            ->assertSessionHasErrors('role');

        $this->assertSame('admin', $admin->fresh()->role);
    }

    /** Suspending somebody takes their keys as well as their status. */
    public function test_suspending_an_account_revokes_its_tokens(): void
    {
        $admin = $this->makeUser('Platform admin');
        $admin->update(['role' => 'admin']);
        $this->student->createToken('phone');

        $this->actingAs($admin)->patch(route('panel.platform.users.update', $this->student), [
            'role' => 'learner',
            'status' => 'suspended',
        ]);

        $this->assertSame(0, $this->student->tokens()->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.update']);
    }
}
