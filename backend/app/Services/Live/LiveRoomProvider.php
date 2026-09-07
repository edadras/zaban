<?php

namespace App\Services\Live;

/**
 * The media server, as the rest of the application is allowed to know it.
 *
 * Video conferencing is a service the product buys or hosts, not a thing it is.
 * Everything above this line - who is in the room, who may speak, what is on
 * screen - is decided and stored here, and the provider is told afterwards. So
 * a class survives the media server being down (the roll, the materials and the
 * questions all still work), and swapping LiveKit for something else is a new
 * class implementing six methods rather than a rewrite.
 *
 * Permissions are pushed rather than pulled for the same reason: the coach's
 * decision is a row in `class_participants`, and this interface is how that row
 * is made true in the room.
 */
interface LiveRoomProvider
{
    /** A name for this provider, for the ledger and for the client. */
    public function name(): string;

    /**
     * Make the room, or do nothing if it already exists.
     *
     * @param  int  $emptyTimeoutSeconds  how long an empty room lingers before
     *                                    the server reaps it. Long enough that
     *                                    a coach reconnecting does not come back
     *                                    to a room that has been swept away.
     */
    public function createRoom(string $room, int $emptyTimeoutSeconds = 900, int $maxParticipants = 0): void;

    public function deleteRoom(string $room): void;

    /**
     * A credential that lets one person into one room, with one set of rights.
     *
     * Short-lived and issued per join, because it carries the permissions: when
     * the coach mutes someone, the old token must not still say they may speak.
     */
    public function issueToken(string $room, RoomIdentity $identity, RoomPermissions $permissions): RoomToken;

    /** Change what someone in the room may publish, while they are in it. */
    public function updatePermissions(string $room, string $identity, RoomPermissions $permissions): void;

    /** Turn off a track the participant is already publishing. */
    public function muteTrack(string $room, string $identity, string $kind, bool $muted): void;

    /** Put someone out of the room. */
    public function removeParticipant(string $room, string $identity): void;

    /**
     * Whether this provider can record a room at all.
     *
     * Separate from `isConfigured` because recording is a second service with
     * its own deployment: a LiveKit that is running happily may have no egress
     * container behind it, and the coach should be told that rather than shown
     * a record button that quietly does nothing.
     */
    public function canRecord(): bool;

    /**
     * Begin recording. Returns the provider's id for the job, or null when it
     * could not be started - the caller reports it, and the class carries on.
     */
    public function startRecording(RecordingRequest $request): ?string;

    /** Stop a recording. Safe to call on one that has already stopped. */
    public function stopRecording(string $egressId): void;

    /**
     * Whether the provider is configured well enough to be used.
     *
     * Checked before a class is started so the coach is told the room is not
     * reachable, rather than discovering it with thirty learners waiting.
     */
    public function isConfigured(): bool;
}
