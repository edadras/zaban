<?php

namespace App\Jobs;

use App\Events\Classroom\ClassBoardEvent;
use App\Models\ClassThread;
use App\Services\Classroom\ClassroomAiService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The assistant's first pass at a question, a few minutes late on purpose.
 *
 * The delay is the point: a board where the machine always answers first is a
 * board where classmates stop bothering, and a class answering itself is worth
 * more than a class being answered. So this waits, and does nothing at all if
 * somebody got there first.
 */
class AnswerClassQuestion implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(public readonly int $threadId) {}

    public function handle(ClassroomAiService $assistant): void
    {
        $thread = ClassThread::with('group.level')->find($this->threadId);

        if ($thread === null) {
            return;
        }

        $reply = $assistant->answerThread($thread);

        if ($reply === null) {
            return;
        }

        event(new ClassBoardEvent($thread->class_group_id, ClassBoardEvent::REPLY_POSTED, [
            'thread_id' => $thread->id,
            'reply_id' => $reply->id,
            'is_ai_answer' => true,
        ]));
    }
}
