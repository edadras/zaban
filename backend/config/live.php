<?php

/*
 * The media server behind live classes.
 *
 * `null` is the default on purpose: a fresh checkout, CI and the test suite all
 * run the whole classroom module without a media server, and only the room
 * itself is missing. Set LIVE_PROVIDER=livekit once a server is reachable.
 */
return [

    'provider' => env('LIVE_PROVIDER', 'null'),

    'livekit' => [
        'key' => env('LIVEKIT_API_KEY', ''),
        'secret' => env('LIVEKIT_API_SECRET', ''),
        // What the client dials.
        'ws_url' => env('LIVEKIT_WS_URL', ''),
        // What the server dials for the admin API. Same host, http(s) scheme.
        'http_url' => env('LIVEKIT_HTTP_URL', ''),
        // Long enough for a two-hour class plus the overrun.
        'token_ttl' => (int) env('LIVEKIT_TOKEN_TTL', 21600),
    ],

    'room' => [
        // How long an empty room survives. A coach whose laptop reboots comes
        // back to the same room rather than to a swept-away one.
        'empty_timeout' => (int) env('LIVE_ROOM_EMPTY_TIMEOUT', 900),
        'max_participants' => (int) env('LIVE_ROOM_MAX_PARTICIPANTS', 0),
    ],

    /*
     * How long before the hour the class is announced. Fifteen minutes is
     * enough to put a book down and not so early that it is forgotten.
     */
    'notify_minutes_before' => (int) env('LIVE_NOTIFY_MINUTES', 15),

    /*
     * How long a practice lock lasts when the coach does not say. Long enough
     * to cover the evening after an afternoon class.
     */
    'practice_lock_hours' => (int) env('LIVE_PRACTICE_LOCK_HOURS', 24),
];
