<?php

namespace App\Events\Classroom;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Something happened on a class's board.
 *
 * The class group's channel rather than a session's: the board outlives any one
 * lesson, and a learner reading it on the bus is not in a room. Same shape as
 * {@see ClassroomEvent} and for the same reason - the fact and the ids travel,
 * never the content, so one place decides whether a person may read a post.
 */
class ClassBoardEvent implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public const THREAD_POSTED = 'thread.posted';

    public const THREAD_UPDATED = 'thread.updated';

    public const REPLY_POSTED = 'reply.posted';

    public const REPLY_ACCEPTED = 'reply.accepted';

    public const ASSIGNMENT_POSTED = 'assignment.posted';

    public const SUBMISSION_MARKED = 'submission.marked';

    public function __construct(
        public readonly int $classGroupId,
        public readonly string $type,
        public readonly array $payload = [],
    ) {}

    /** @return array<int, Channel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("class-group.{$this->classGroupId}")];
    }

    public function broadcastAs(): string
    {
        return 'class-board';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'type' => $this->type,
            'class_group_id' => $this->classGroupId,
            'at' => now()->toIso8601String(),
        ] + $this->payload;
    }
}
