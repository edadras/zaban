<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A recording or a piece of writing has finished being marked.
 *
 * These two are the only things in the app a learner waits on: everything else
 * is graded in the request that submitted it. Scoring a recording means a
 * transcription, a forced alignment and a model call, which is seconds at best
 * and can be a minute — so the client submits, gets an id, and then either
 * polls or listens.
 *
 * Polling still works and is still the fallback; this is what makes listening
 * possible. It carries the id and the verdict, not the scores: the client
 * fetches the attempt, so there is one shape of that data and one place it is
 * authorised.
 */
class AttemptScored implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public int $userId,
        /** `speech` or `writing`. */
        public string $kind,
        public int $attemptId,
        /** `scored` or `failed`. */
        public string $status,
    ) {}

    /** @return array<int, Channel> */
    public function broadcastOn(): array
    {
        // Private: an attempt belongs to one person, and the channel name is
        // authorised in routes/channels.php against that person's id.
        return [new PrivateChannel("user.{$this->userId}")];
    }

    public function broadcastAs(): string
    {
        return 'attempt.scored';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'kind' => $this->kind,
            'attempt_id' => $this->attemptId,
            'status' => $this->status,
        ];
    }
}
