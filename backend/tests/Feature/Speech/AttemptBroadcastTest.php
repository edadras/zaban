<?php

namespace Tests\Feature\Speech;

use App\Events\AttemptScored;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Facades\Broadcast;
use Tests\TestCase;

/**
 * The websocket path existed only in configuration: `laravel/reverb` was not
 * installed, `config/broadcasting.php` did not exist, and docker-compose ran a
 * `reverb` service against a command the application did not have.
 *
 * What matters about it now is the authorisation. Every event the app pushes is
 * about work one person submitted, so there is exactly one channel shape and it
 * is named after them.
 */
class AttemptBroadcastTest extends TestCase
{
    public function test_a_scored_attempt_is_announced_on_its_own_learners_channel(): void
    {
        $event = new AttemptScored(7, 'speech', 42, 'scored');

        $channels = $event->broadcastOn();

        $this->assertCount(1, $channels);
        $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
        $this->assertSame('private-user.7', (string) $channels[0]);
        $this->assertSame('attempt.scored', $event->broadcastAs());
    }

    /**
     * The scores themselves are not in the payload. The client fetches the
     * attempt, so there is one shape of that data and one place it is
     * authorised - a broadcast that carried the scores would be a second.
     */
    public function test_the_payload_says_what_happened_and_nothing_more(): void
    {
        $event = new AttemptScored(7, 'writing', 42, 'failed');

        $this->assertSame(
            ['kind' => 'writing', 'attempt_id' => 42, 'status' => 'failed'],
            $event->broadcastWith(),
        );
    }

    public function test_a_learner_may_only_listen_to_their_own_channel(): void
    {
        $mine = new \App\Models\User(['id' => 7]);
        $mine->id = 7;

        $callback = Broadcast::getChannels()['user.{id}'] ?? null;
        $this->assertNotNull($callback, 'the user channel must be registered');

        $this->assertTrue($callback($mine, 7));
        $this->assertFalse($callback($mine, 8));
    }
}
