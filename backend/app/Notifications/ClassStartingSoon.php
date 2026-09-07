<?php

namespace App\Notifications;

use App\Models\ClassSession;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * "Your class starts in fifteen minutes."
 *
 * Two channels, and they do different jobs. The database row is the bell: it
 * survives being offline, and it is still there when the learner opens the app
 * an hour later. The broadcast is the interruption, and only reaches someone
 * who is already looking at a screen.
 *
 * A learner who has turned reminders off gets neither, which is checked by the
 * sender rather than here - a notification should not have opinions about
 * whether it was right to send it.
 */
class ClassStartingSoon extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly ClassSession $session,
        /** Whether this is the scheduled warning or the coach opening the door. */
        public readonly bool $isLiveNow = false,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $group = $this->session->group;

        return [
            'kind' => $this->isLiveNow ? 'class.live' : 'class.starting',
            'class_session_id' => $this->session->id,
            'class_group_id' => $this->session->class_group_id,
            'title' => $this->session->title ?: $group?->title,
            'coach' => $this->session->coach?->name,
            'starts_at' => $this->session->starts_at?->toIso8601String(),
            'minutes_until' => max(0, (int) round(now()->diffInMinutes($this->session->starts_at, absolute: false))),
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }

    public function broadcastType(): string
    {
        return 'class.notice';
    }
}
