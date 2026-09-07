<?php

namespace App\Http\Controllers\Api\V1\Classroom;

use App\Http\Controllers\Api\V1\ApiController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;

/**
 * Live updates for a bearer-token client.
 *
 * Laravel's own `/broadcasting/auth` sits behind the session guard, which is
 * exactly right for the web panel and useless to the app. Rather than widen
 * that route's middleware - and with it the CSRF rules the panel depends on -
 * this is the same authorisation reached the way the app authenticates
 * everything else. The channel callbacks in `routes/channels.php` are the ones
 * that decide; nothing about who may listen is re-implemented here.
 */
class RealtimeController extends ApiController
{
    /**
     * Where to connect, and with what key.
     *
     * The key is public by design - it identifies the application to the
     * socket server and grants nothing on its own; every private channel is
     * authorised separately below.
     */
    public function show(Request $request)
    {
        $driver = (string) config('broadcasting.default');
        $connection = config("broadcasting.connections.{$driver}");

        // Nothing configured is a legitimate answer, not an error: the clients
        // fall back to asking the API periodically.
        if (! is_array($connection) || ! in_array($driver, ['reverb', 'pusher'], true)) {
            return $this->ok(['driver' => 'null', 'enabled' => false]);
        }

        $options = $connection['options'] ?? [];
        $scheme = (string) ($options['scheme'] ?? 'https');

        return $this->ok([
            'driver' => $driver,
            'enabled' => filled($connection['key'] ?? null),
            'key' => $connection['key'] ?? null,
            'host' => $options['host'] ?? null,
            'port' => (int) ($options['port'] ?? ($scheme === 'https' ? 443 : 80)),
            'scheme' => $scheme,
            'auth_endpoint' => route('classroom.realtime.auth'),
            // The channel this person may always listen on, so a client does
            // not have to work out its own name.
            'user_channel' => 'user.'.$request->user()->id,
        ]);
    }

    public function authorise(Request $request)
    {
        $request->validate([
            'socket_id' => ['required', 'string', 'max:120'],
            'channel_name' => ['required', 'string', 'max:160'],
        ]);

        // Broadcast::auth() runs the callbacks in routes/channels.php and
        // aborts 403 when they refuse, which is the behaviour we want verbatim.
        return response()->json(Broadcast::auth($request));
    }
}
