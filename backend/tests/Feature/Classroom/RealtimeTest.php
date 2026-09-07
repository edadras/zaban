<?php

namespace Tests\Feature\Classroom;

use Illuminate\Support\Facades\Broadcast;

/**
 * Live updates for a bearer-token client.
 *
 * Laravel's own broadcasting-auth route is behind the session guard, which is
 * right for the panel and useless to the app. What matters is that reaching it
 * a second way did not weaken it: the channel callbacks still decide, and a
 * learner from another school is still refused.
 */
class RealtimeTest extends ClassroomTestCase
{
    public function test_an_unconfigured_installation_says_so_rather_than_failing(): void
    {
        config(['broadcasting.default' => 'null']);

        $this->actingAs($this->student)
            ->getJson('/api/v1/realtime')
            ->assertOk()
            ->assertJsonPath('data.driver', 'null')
            ->assertJsonPath('data.enabled', false);
    }

    public function test_a_configured_installation_hands_out_where_to_connect(): void
    {
        $this->configureReverb();

        $response = $this->actingAs($this->student)
            ->getJson('/api/v1/realtime')
            ->assertOk();

        $this->assertSame('reverb', $response->json('data.driver'));
        $this->assertTrue($response->json('data.enabled'));
        $this->assertSame('live.example.test', $response->json('data.host'));
        $this->assertSame(443, $response->json('data.port'));
        // So a client does not have to work out its own channel name.
        $this->assertSame('user.'.$this->student->id, $response->json('data.user_channel'));
    }

    public function test_the_key_is_never_the_secret(): void
    {
        $this->configureReverb();

        $body = $this->actingAs($this->student)->getJson('/api/v1/realtime')->content();

        $this->assertStringNotContainsString('reverb-secret', $body);
        $this->assertStringNotContainsString('reverb-app-id', $body);
    }

    public function test_a_learner_on_the_roll_may_listen_to_their_class(): void
    {
        $this->configureReverb();
        $session = $this->makeSession();

        $this->actingAs($this->student)
            ->postJson('/api/v1/realtime/auth', [
                'socket_id' => '123.456',
                'channel_name' => "private-class-session.{$session->id}",
            ])
            ->assertOk()
            ->assertJsonStructure(['auth']);
    }

    /** The whole point of the channel authorisation, reached the new way. */
    public function test_somebody_from_another_school_may_not(): void
    {
        $this->configureReverb();
        $session = $this->makeSession();

        $this->actingAs($this->outsider)
            ->postJson('/api/v1/realtime/auth', [
                'socket_id' => '123.456',
                'channel_name' => "private-class-session.{$session->id}",
            ])
            ->assertStatus(403);
    }

    public function test_nobody_may_listen_on_somebody_elses_own_channel(): void
    {
        $this->configureReverb();

        $this->actingAs($this->student)
            ->postJson('/api/v1/realtime/auth', [
                'socket_id' => '123.456',
                'channel_name' => 'private-user.'.$this->otherStudent->id,
            ])
            ->assertStatus(403);

        $this->actingAs($this->student)
            ->postJson('/api/v1/realtime/auth', [
                'socket_id' => '123.456',
                'channel_name' => 'private-user.'.$this->student->id,
            ])
            ->assertOk();
    }

    public function test_a_signed_out_visitor_gets_nothing(): void
    {
        $this->getJson('/api/v1/realtime')->assertStatus(401);
        $this->postJson('/api/v1/realtime/auth', [
            'socket_id' => '123.456',
            'channel_name' => 'private-user.1',
        ])->assertStatus(401);
    }

    /**
     * Switch the installation to Reverb mid-test.
     *
     * The channel callbacks are registered against whichever broadcaster was
     * resolved at boot, so changing the driver afterwards means re-running
     * routes/channels.php against the new one. Production never does this - the
     * driver is set before the framework boots - which is why it only bites
     * here.
     */
    private function configureReverb(): void
    {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'reverb-key',
            'broadcasting.connections.reverb.secret' => 'reverb-secret',
            'broadcasting.connections.reverb.app_id' => 'reverb-app-id',
            'broadcasting.connections.reverb.options.host' => 'live.example.test',
            'broadcasting.connections.reverb.options.port' => 443,
            'broadcasting.connections.reverb.options.scheme' => 'https',
        ]);

        Broadcast::purge('null');
        require base_path('routes/channels.php');
    }
}
