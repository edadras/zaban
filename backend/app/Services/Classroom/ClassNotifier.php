<?php

namespace App\Services\Classroom;

use App\Models\ClassSession;
use App\Models\User;
use App\Notifications\ClassStartingSoon;
use Illuminate\Support\Collection;

/**
 * Telling people a class is about to start.
 *
 * Who gets told is a decision, not a broadcast: the roll by default, or the few
 * the coach names when they want a handful of learners in the room and not the
 * whole group. The learner's own notification setting is honoured for the
 * scheduled warning and overridden when the coach opens the door by hand -
 * somebody being summoned into a class that is running now is not a reminder
 * they can have turned off.
 */
class ClassNotifier
{
    /**
     * @param  list<int>  $userIds  empty means the whole roll
     * @return int how many people were told
     */
    public function announce(ClassSession $session, array $userIds = [], bool $isLiveNow = false): int
    {
        $recipients = $this->recipients($session, $userIds);

        if (! $isLiveNow) {
            $recipients = $recipients->filter(
                fn (User $u) => $u->settings?->reminder_enabled ?? true
            );
        }

        if ($recipients->isEmpty()) {
            return 0;
        }

        $session->loadMissing(['group', 'coach']);

        foreach ($recipients as $user) {
            $user->notify(new ClassStartingSoon($session, $isLiveNow));
        }

        if (! $isLiveNow) {
            $session->forceFill(['notified_at' => now()])->save();
        }

        return $recipients->count();
    }

    /** @return Collection<int, User> */
    private function recipients(ClassSession $session, array $userIds): Collection
    {
        $roll = $session->group?->students()->get() ?? collect();

        if ($userIds === []) {
            return $roll;
        }

        // Only people actually on the roll: a coach must not be able to summon
        // a learner from another class into their room.
        return $roll->whereIn('id', $userIds)->values();
    }
}
