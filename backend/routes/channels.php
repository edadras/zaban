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
