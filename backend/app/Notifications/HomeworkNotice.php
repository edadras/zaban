<?php

namespace App\Notifications;

use App\Models\Assignment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * Homework set, or homework given back.
 *
 * Two moments a learner genuinely wants to be interrupted for, and no others:
 * a reminder every evening until it is done would be the app nagging, and an
 * app that nags gets its notifications turned off.
 */
class HomeworkNotice extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Assignment $assignment,
        /** homework.set | homework.returned | homework.due */
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
            'assignment_id' => $this->assignment->id,
            'class_group_id' => $this->assignment->class_group_id,
            'title' => $this->assignment->title,
            'homework_kind' => $this->assignment->kind,
            'class' => $this->assignment->group?->title,
            'due_at' => $this->assignment->due_at?->toIso8601String(),
        ] + $this->extra;
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }

    public function broadcastType(): string
    {
        return 'class.homework';
    }
}
