<?php

use App\Models\ClassGroup;
use App\Models\ClassSession;
use App\Services\Classroom\SchoolService;
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
    $session = ClassSession::with('group.school')->find($id);

    if ($session === null) {
        return false;
    }
    if ($session->coach_id === $user->id) {
        return ['id' => $user->id, 'name' => $user->name, 'role' => 'coach'];
    }
    if (app(SchoolService::class)->isManager($session->group->school, $user)) {
        return ['id' => $user->id, 'name' => $user->name, 'role' => 'coach'];
    }
    if ($session->group?->students()->whereKey($user->id)->exists()) {
        return ['id' => $user->id, 'name' => $user->name, 'role' => 'student'];
    }

    return false;
});

/*
 * A class's board and its homework.
 *
 * The group rather than a session: the board outlives any one lesson, and a
 * learner reading it on the bus is not in a room. Same three answers as the
 * room's channel - the coach, a manager of the school, somebody on the roll -
 * because it is the same question about the same people.
 */
Broadcast::channel('class-group.{id}', function ($user, $id) {
    $group = ClassGroup::with('school')->find($id);

    if ($group === null) {
        return false;
    }
    if ($group->coach_id === $user->id) {
        return ['id' => $user->id, 'name' => $user->name, 'role' => 'coach'];
    }
    if (app(SchoolService::class)->isManager($group->school, $user)) {
        return ['id' => $user->id, 'name' => $user->name, 'role' => 'coach'];
    }
    if ($group->students()->whereKey($user->id)->exists()) {
        return ['id' => $user->id, 'name' => $user->name, 'role' => 'student'];
    }

    return false;
});
