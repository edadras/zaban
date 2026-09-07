<?php

namespace App\Http\Controllers\Panel;

use App\Models\ClassSession;
use App\Models\User;
use App\Services\Live\LiveRoomProvider;
use App\Support\PanelAccess;

/**
 * The live class as a web page.
 *
 * The console itself is JavaScript talking to the classroom API - the same
 * endpoints the mobile client uses, so there is one implementation of "mute
 * this learner" rather than two that can drift apart. To reach them the page is
 * handed a bearer token minted here, deliberately narrow: one per person at a
 * time, named so it can be recognised, scoped to the classroom, and expiring
 * with the class rather than living in the database for ever.
 */
class RoomController extends PanelController
{
    /** Long enough for a class that runs over, short enough not to be a key. */
    private const TOKEN_HOURS = 6;

    public function __construct(PanelAccess $access, private readonly LiveRoomProvider $rooms)
    {
        parent::__construct($access);
    }

    public function show(ClassSession $session)
    {
        $session->load(['group.school', 'coach']);
        $this->allow($this->access->canRunSession($this->me(), $session));

        return view('panel.sessions.room', [
            'session' => $session,
            'apiToken' => $this->mintToken($this->me()),
            'mediaConfigured' => $this->rooms->isConfigured(),
            'provider' => $this->rooms->name(),
            'reverb' => [
                'key' => config('broadcasting.connections.reverb.key'),
                'host' => config('broadcasting.connections.reverb.options.host'),
                'port' => (int) config('broadcasting.connections.reverb.options.port', 443),
                'scheme' => config('broadcasting.connections.reverb.options.scheme', 'https'),
            ],
        ]);
    }

    /** Replace rather than accumulate: one live-room key per person. */
    private function mintToken(User $user): string
    {
        $user->tokens()->where('name', 'panel-room')->delete();

        return $user->createToken(
            'panel-room',
            ['classroom'],
            now()->addHours(self::TOKEN_HOURS),
        )->plainTextToken;
    }
}
