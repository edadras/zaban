<?php

namespace App\Events\Classroom;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Anything that happens in a live class, on the one channel that class owns.
 *
 * A single event class rather than a dozen: every one of these is "the room
 * changed, here is how", the client switches on `type` anyway, and one channel
 * with one authorisation rule is one place to get the authorisation right.
 *
 * What travels is the fact and the ids, never the content. A learner who is
 * shown a material still fetches it through the API, so there is one place that
 * decides whether they may have it.
 */
class ClassroomEvent implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public const SESSION_STARTED = 'session.started';

    public const SESSION_ENDED = 'session.ended';

    public const PARTICIPANT_JOINED = 'participant.joined';

    public const PARTICIPANT_LEFT = 'participant.left';

    public const PARTICIPANT_UPDATED = 'participant.updated';

    public const HAND_RAISED = 'hand.raised';

    public const MATERIAL_SHARED = 'material.shared';

    public const MATERIAL_CLOSED = 'material.closed';

    public const MATERIAL_ADDED = 'material.added';

    public const MATERIAL_REMOVED = 'material.removed';

    public const STAGE_UPDATED = 'stage.updated';

    public const WHITEBOARD_UPDATED = 'whiteboard.updated';

    public const CHAT_MESSAGE = 'chat.message';

    public const QUESTION_OPENED = 'question.opened';

    public const QUESTION_CLOSED = 'question.closed';

    public const ANSWER_RECEIVED = 'answer.received';

    public const PRACTICE_LOCKED = 'practice.locked';

    public const PRACTICE_RELEASED = 'practice.released';

    public const RECORDING_CHANGED = 'recording.changed';

    public function __construct(
        public readonly int $classSessionId,
        public readonly string $type,
        public readonly array $payload = [],
    ) {}

    /** @return array<int, Channel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("class-session.{$this->classSessionId}")];
    }

    public function broadcastAs(): string
    {
        return 'classroom';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'type' => $this->type,
            'class_session_id' => $this->classSessionId,
            'at' => now()->toIso8601String(),
        ] + $this->payload;
    }
}
