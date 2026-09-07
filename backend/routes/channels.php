<?php

use Illuminate\Support\Facades\Broadcast;

/*
 * Who may listen to what.
 *
 * One channel per person, and nothing else. Everything the app pushes is about
 * work that person submitted — a recording being marked, a piece of writing
 * being read — so there is no shared channel to get the authorisation wrong on.
 */

Broadcast::channel('user.{id}', fn ($user, $id) => (int) $user->id === (int) $id);

/*
 * A live class.
 *
 * Everyone in the room sees the same stream of events - who joined, who was
 * muted, what is on screen - so unlike the per-person channel above this one is
 * shared, and the authorisation has to do real work: the coach, a manager of
 * the school, or somebody on the roll. Nobody else, including learners at the
 * same school in a different class.
 */
Broadcast::channel('class-session.{id}', function ($user, $id) {
    $session = \App\Models\ClassSession::with('group.school')->find($id);

    if ($session === null) {
        return false;
    }
    if ($session->coach_id === $user->id) {
        return ['id' => $user->id, 'name' => $user->name, 'role' => 'coach'];
    }
    if (app(\App\Services\Classroom\SchoolService::class)->isManager($session->group->school, $user)) {
        return ['id' => $user->id, 'name' => $user->name, 'role' => 'coach'];
    }
    if ($session->group?->students()->whereKey($user->id)->exists()) {
        return ['id' => $user->id, 'name' => $user->name, 'role' => 'student'];
    }

    return false;
});
