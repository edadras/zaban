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

    /*
     * Recording a class.
     *
     * LiveKit's egress service composites the room into one video file. Two
     * ways out of it: `file`, which writes into a directory this application
     * can also read - the usual arrangement is one Docker volume mounted into
     * both containers - or `s3`, which hands it to object storage. `file` is
     * the default because it needs nothing bought.
     *
     * Off by default: recording a classroom is a decision about the people in
     * it, not a default anyone should acquire by upgrading.
     */
    'recording' => [
        'enabled' => (bool) env('LIVE_RECORDING', false),

        // Whether a class starts recording the moment the coach opens it, or
        // waits for them to press record.
        'auto_start' => (bool) env('LIVE_RECORDING_AUTOSTART', false),

        // 'file' or 's3'.
        'output' => env('LIVE_RECORDING_OUTPUT', 'file'),

        // 'grid', 'speaker' or 'single-speaker'. Speaker follows whoever is
        // talking, which is what a lesson mostly wants.
        'layout' => env('LIVE_RECORDING_LAYOUT', 'speaker'),

        // Where LiveKit writes, as LiveKit sees it.
        'directory' => env('LIVE_RECORDING_DIR', '/recordings'),

        // The same place, as this application sees it. Only differs when the
        // two run in separate containers.
        'local_directory' => env('LIVE_RECORDING_LOCAL_DIR', storage_path('app/recordings')),

        's3' => [
            'bucket' => env('LIVE_RECORDING_S3_BUCKET', ''),
            'region' => env('LIVE_RECORDING_S3_REGION', ''),
            'endpoint' => env('LIVE_RECORDING_S3_ENDPOINT', ''),
            'access_key' => env('LIVE_RECORDING_S3_KEY', ''),
            'secret' => env('LIVE_RECORDING_S3_SECRET', ''),
        ],
    ],

    /*
     * Where the browser and the app connect for live updates. Read from the
     * broadcasting config so there is one place a Reverb host is set.
     */
    'realtime' => [
        'driver' => env('BROADCAST_CONNECTION', 'null'),
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
