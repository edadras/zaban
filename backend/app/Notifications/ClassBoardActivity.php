<?php

namespace App\Notifications;

use App\Models\ClassThread;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Something happened on the class's board.
 *
 * Two kinds, deliberately: a new question goes to the class, and a reply goes
 * only to whoever asked. A board that tells everybody about every reply is a
 * board people turn off, and then it tells them nothing at all.
 */
class ClassBoardActivity extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly ClassThread $thread,
        /** class.board.thread | class.board.reply */
        public readonly string $kind,
        public readonly array $extra = [],
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => $this->kind,
            'class_thread_id' => $this->thread->id,
            'class_group_id' => $this->thread->class_group_id,
            'title' => $this->thread->title,
            'class' => $this->thread->group?->title,
            'excerpt' => Str::limit((string) $this->thread->body, 120),
        ] + $this->extra;
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }

    public function broadcastType(): string
    {
        return 'class.board';
    }
}
