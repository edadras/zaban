<?php

namespace App\Services\Classroom;

use App\Events\Classroom\ClassroomEvent;
use App\Models\ClassMaterial;
use App\Models\ClassParticipant;
use App\Models\ClassSession;
use App\Models\User;
use App\Services\Live\LiveRoomProvider;
use App\Services\Live\RoomIdentity;
use App\Services\Live\RoomPermissions;
use App\Services\Live\RoomToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The live class: opening the room, letting people in, and what the coach may
 * do to them once they are.
 *
 * Two rules run through all of it.
 *
 * The database decides and the media server is told. Every permission is a row
 * before it is a call, so a learner who reconnects comes back exactly as the
 * coach left them, and the roll survives the media server being unreachable.
 *
 * A class does not need the media server to be useful. Materials, questions,
 * answers, attendance and the practice lock all work without a room; only the
 * faces and voices are missing. So nothing here refuses to run when the
 * provider is unconfigured - it reports it, and carries on.
 */
class ClassroomService
{
    public function __construct(
        private readonly LiveRoomProvider $rooms,
        private readonly RecordingService $recordings,
        private readonly RoomStageService $stage,
    ) {}

    /**
     * The coach opens the class.
     *
     * Idempotent: a coach whose connection drops calls this again and gets the
     * same live session rather than a second one.
     */
    public function start(ClassSession $session): ClassSession
    {
        if ($session->status === ClassSession::CANCELLED) {
            throw new ClassroomException('This class was cancelled.');
        }
        if ($session->status === ClassSession::ENDED) {
            throw new ClassroomException('This class has already ended.');
        }

        if ($session->status !== ClassSession::LIVE) {
            $session->forceFill([
                'status' => ClassSession::LIVE,
                'started_at' => $session->started_at ?? now(),
            ])->save();
        }

        if ($this->rooms->isConfigured()) {
            $this->rooms->createRoom(
                $session->room_name,
                (int) config('live.room.empty_timeout'),
                (int) config('live.room.max_participants'),
            );
        }

        /*
         * A school that has asked for every class to be recorded gets it
         * without the coach remembering. A failure here is logged and not
         * thrown: the class is already open with people arriving, and losing
         * the recording is better than losing the lesson. The console shows
         * the failed state, so it is not a silent loss either.
         */
        if ($this->recordings->startsAutomatically()) {
            try {
                $this->recordings->start($session);
            } catch (ClassroomException $e) {
                Log::warning('a class opened but could not start recording', [
                    'class_session_id' => $session->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        event(new ClassroomEvent($session->id, ClassroomEvent::SESSION_STARTED, [
            'room_available' => $this->rooms->isConfigured(),
        ]));

        return $session->refresh();
    }

    public function end(ClassSession $session): ClassSession
    {
        // Before the room goes: an egress against a deleted room produces a
        // truncated file and an error nobody can act on.
        if ($session->isRecording()) {
            $this->recordings->stop($session);
        }

        $session->forceFill([
            'status' => ClassSession::ENDED,
            'ended_at_actual' => now(),
        ])->save();

        // Everyone still marked present is credited for the time up to now,
        // because a class ending is not something learners click through.
        $session->participants()->where('is_present', true)->get()
            ->each(fn (ClassParticipant $p) => $this->markLeft($p));

        if ($this->rooms->isConfigured()) {
            $this->rooms->deleteRoom($session->room_name);
        }

        event(new ClassroomEvent($session->id, ClassroomEvent::SESSION_ENDED));

        return $session->refresh();
    }

    /**
     * Someone knocks. Returns their seat and, if there is a room, the key to it.
     *
     * @return array{participant: ClassParticipant, token: RoomToken, room_available: bool}
     */
    public function join(ClassSession $session, User $user, bool $asCoach = false): array
    {
        if (! $asCoach && ! $session->isJoinable()) {
            throw new ClassroomException('This class is not open.');
        }

        $participant = DB::transaction(function () use ($session, $user, $asCoach) {
            $participant = ClassParticipant::firstOrNew([
                'class_session_id' => $session->id,
                'user_id' => $user->id,
            ]);

            $participant->role = $asCoach ? 'coach' : ($participant->role ?: 'student');

            // A coach always arrives able to teach. A learner arrives with
            // whatever the coach last decided, and muted the first time.
            if ($asCoach) {
                $participant->can_publish_audio = true;
                $participant->can_publish_video = true;
            } elseif (! $participant->exists) {
                $participant->can_publish_audio = false;
                $participant->can_publish_video = false;
            }

            $participant->is_present = true;
            $participant->last_joined_at = now();
            $participant->first_joined_at ??= now();
            $participant->left_at = null;
            $participant->save();

            return $participant;
        });

        $token = $this->tokenFor($session, $user, $participant);

        event(new ClassroomEvent($session->id, ClassroomEvent::PARTICIPANT_JOINED, [
            'participant' => $this->participantPayload($participant->load('user')),
        ]));

        return [
            'participant' => $participant,
            'token' => $token,
            'room_available' => $this->rooms->isConfigured(),
        ];
    }

    public function leave(ClassSession $session, User $user): void
    {
        $participant = ClassParticipant::where('class_session_id', $session->id)
            ->where('user_id', $user->id)->first();

        if ($participant === null) {
            return;
        }

        $this->markLeft($participant);

        event(new ClassroomEvent($session->id, ClassroomEvent::PARTICIPANT_LEFT, [
            'user_id' => $user->id,
        ]));
    }

    /**
     * The coach turns a learner's microphone or camera on or off.
     *
     * Both halves of it: the permission stops them publishing again, and the
     * mute stops the track that is already flowing. One without the other is a
     * learner who can talk over the coach for as long as they keep the current
     * track open, or one who is silenced and simply unmutes.
     */
    public function setMedia(ClassSession $session, ClassParticipant $participant, ?bool $audio, ?bool $video): ClassParticipant
    {
        if ($audio !== null) {
            $participant->can_publish_audio = $audio;
        }
        if ($video !== null) {
            $participant->can_publish_video = $video;
        }
        $participant->save();

        if ($this->rooms->isConfigured()) {
            $identity = RoomIdentity::forUser($participant->user_id, '')->identity;
            $this->rooms->updatePermissions(
                $session->room_name,
                $identity,
                $this->permissionsFor($participant),
            );

            if ($audio === false) {
                $this->rooms->muteTrack($session->room_name, $identity, 'audio', true);
            }
            if ($video === false) {
                $this->rooms->muteTrack($session->room_name, $identity, 'video', true);
            }
        }

        event(new ClassroomEvent($session->id, ClassroomEvent::PARTICIPANT_UPDATED, [
            'participant' => $this->participantPayload($participant->fresh()->load('user')),
        ]));

        return $participant->refresh();
    }

    /** Everyone at once - what a coach does when a discussion has to stop. */
    public function muteEveryone(ClassSession $session): int
    {
        $students = $session->participants()->where('role', '!=', 'coach')->get();

        foreach ($students as $participant) {
            $this->setMedia($session, $participant, audio: false, video: null);
        }

        return $students->count();
    }

    public function raiseHand(ClassSession $session, User $user, bool $raised): void
    {
        ClassParticipant::where('class_session_id', $session->id)
            ->where('user_id', $user->id)
            ->update(['hand_raised_at' => $raised ? now() : null]);

        event(new ClassroomEvent($session->id, ClassroomEvent::HAND_RAISED, [
            'user_id' => $user->id,
            'raised' => $raised,
        ]));
    }

    public function removeFromRoom(ClassSession $session, ClassParticipant $participant): void
    {
        $this->markLeft($participant);

        if ($this->rooms->isConfigured()) {
            $this->rooms->removeParticipant(
                $session->room_name,
                RoomIdentity::forUser($participant->user_id, '')->identity,
            );
        }

        event(new ClassroomEvent($session->id, ClassroomEvent::PARTICIPANT_LEFT, [
            'user_id' => $participant->user_id,
            'removed' => true,
        ]));
    }

    /**
     * Put a material on everyone's screen.
     *
     * One at a time: sharing a second closes the first, because two things
     * claiming to be "what we are looking at" is how a class loses its place.
     */
    public function shareMaterial(ClassSession $session, ClassMaterial $material): ClassMaterial
    {
        DB::transaction(function () use ($session, $material) {
            $session->materials()->whereKeyNot($material->id)
                ->whereNotNull('shared_at')->update(['shared_at' => null]);
            $material->forceFill(['shared_at' => now()])->save();
            $this->stage->resetForShare($session);
        });

        $session->refresh();

        event(new ClassroomEvent($session->id, ClassroomEvent::MATERIAL_SHARED, [
            'material_id' => $material->id,
            'kind' => $material->kind,
            'title' => $material->title,
            'stage' => $this->stage->publicStage($this->stage->current($session)),
        ]));

        return $material->refresh();
    }

    public function closeMaterial(ClassSession $session, ClassMaterial $material): void
    {
        $material->forceFill(['shared_at' => null])->save();

        event(new ClassroomEvent($session->id, ClassroomEvent::MATERIAL_CLOSED, [
            'material_id' => $material->id,
        ]));
    }

    /** A fresh key, for a client whose token is about to expire mid-class. */
    public function tokenFor(ClassSession $session, User $user, ?ClassParticipant $participant = null): RoomToken
    {
        $participant ??= ClassParticipant::where('class_session_id', $session->id)
            ->where('user_id', $user->id)->firstOrFail();

        return $this->rooms->issueToken(
            $session->room_name,
            RoomIdentity::forUser($user->id, $user->name, [
                'role' => $participant->role,
                'class_session_id' => $session->id,
            ]),
            $this->permissionsFor($participant),
        );
    }

    private function permissionsFor(ClassParticipant $participant): RoomPermissions
    {
        if ($participant->role === 'coach') {
            return RoomPermissions::coach();
        }

        return RoomPermissions::listener()->with(
            audio: $participant->can_publish_audio,
            video: $participant->can_publish_video,
        );
    }

    /**
     * Close out a stint in the room and add it to the total.
     *
     * Accumulated rather than derived from first/last, because a learner whose
     * connection drops three times has been present for three stints and not
     * for the whole span between the first and the last.
     */
    private function markLeft(ClassParticipant $participant): void
    {
        $seconds = $participant->last_joined_at
            ? max(0, now()->diffInSeconds($participant->last_joined_at, absolute: true))
            : 0;

        $participant->forceFill([
            'is_present' => false,
            'left_at' => now(),
            'hand_raised_at' => null,
            'seconds_present' => $participant->seconds_present + $seconds,
        ])->save();
    }

    /** @return array<string, mixed> */
    public function participantPayload(ClassParticipant $participant): array
    {
        return [
            'id' => $participant->id,
            'user_id' => $participant->user_id,
            'name' => $participant->user?->name,
            'role' => $participant->role,
            'can_publish_audio' => $participant->can_publish_audio,
            'can_publish_video' => $participant->can_publish_video,
            'is_present' => $participant->is_present,
            'hand_raised' => $participant->hand_raised_at !== null,
            'seconds_present' => $participant->seconds_present,
        ];
    }
}
