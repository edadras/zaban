<?php

namespace App\Services\Live;

/**
 * No media server.
 *
 * The default until LiveKit is configured, and what the tests run against.
 * Everything else in a class works without it - the roll, the schedule, the
 * materials, the questions, the practice lock - so the honest thing is to let
 * those work and report that the room itself is unavailable, rather than
 * refusing to run a class at all.
 *
 * It records what it was asked to do so a test can assert the coach's decision
 * reached the provider.
 */
class NullRoomProvider implements LiveRoomProvider
{
    /** @var list<array{0:string,1:array}> */
    public array $calls = [];

    public function name(): string
    {
        return 'null';
    }

    public function isConfigured(): bool
    {
        return false;
    }

    public function createRoom(string $room, int $emptyTimeoutSeconds = 900, int $maxParticipants = 0): void
    {
        $this->calls[] = ['createRoom', compact('room', 'emptyTimeoutSeconds', 'maxParticipants')];
    }

    public function deleteRoom(string $room): void
    {
        $this->calls[] = ['deleteRoom', compact('room')];
    }

    public function issueToken(string $room, RoomIdentity $identity, RoomPermissions $permissions): RoomToken
    {
        $this->calls[] = ['issueToken', ['room' => $room, 'identity' => $identity->identity]];

        // No token, and the client is told so by the empty string rather than
        // by a fake one that would fail confusingly at the socket.
        return new RoomToken('', '', $room, $identity->identity, $this->name(), 0);
    }

    public function updatePermissions(string $room, string $identity, RoomPermissions $permissions): void
    {
        $this->calls[] = ['updatePermissions', [
            'room' => $room,
            'identity' => $identity,
            'audio' => $permissions->canPublishAudio,
            'video' => $permissions->canPublishVideo,
        ]];
    }

    public function muteTrack(string $room, string $identity, string $kind, bool $muted): void
    {
        $this->calls[] = ['muteTrack', compact('room', 'identity', 'kind', 'muted')];
    }

    public function removeParticipant(string $room, string $identity): void
    {
        $this->calls[] = ['removeParticipant', compact('room', 'identity')];
    }

    public function canRecord(): bool
    {
        return false;
    }

    public function startRecording(RecordingRequest $request): ?string
    {
        $this->calls[] = ['startRecording', ['room' => $request->room, 'output' => $request->output]];

        return null;
    }

    public function stopRecording(string $egressId): void
    {
        $this->calls[] = ['stopRecording', compact('egressId')];
    }
}
