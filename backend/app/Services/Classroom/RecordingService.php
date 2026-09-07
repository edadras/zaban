<?php

namespace App\Services\Classroom;

use App\Events\Classroom\ClassroomEvent;
use App\Models\ClassSession;
use App\Models\MediaAsset;
use App\Models\User;
use App\Services\Live\LiveRoomProvider;
use App\Services\Live\RecordingRequest;
use App\Services\Live\RecordingResult;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Recording a class, and letting the right people watch it again.
 *
 * The media server does the recording; this decides when, keeps the state that
 * survives it, and turns the finished file into the same kind of media asset as
 * everything else the product plays - so the existing signed-URL streaming
 * endpoint serves it and there is not a second way to hand a video to a
 * learner.
 *
 * The state matters as much as the file. A class that believes it is being
 * recorded and is not is worse than one that knows it is not, so a failure to
 * start is a refusal the coach sees rather than a log line nobody reads.
 */
class RecordingService
{
    public function __construct(private readonly LiveRoomProvider $rooms) {}

    public function isAvailable(): bool
    {
        return (bool) config('live.recording.enabled') && $this->rooms->canRecord();
    }

    /** Whether a class should begin recording the moment it opens. */
    public function startsAutomatically(): bool
    {
        return $this->isAvailable() && (bool) config('live.recording.auto_start');
    }

    public function start(ClassSession $session): ClassSession
    {
        if (! $this->isAvailable()) {
            throw new ClassroomException('Recording is not available on this installation.');
        }
        if ($session->isRecording()) {
            return $session;
        }
        if ($session->status !== ClassSession::LIVE) {
            throw new ClassroomException('A class has to be running before it can be recorded.');
        }

        $session->forceFill([
            'recording_status' => ClassSession::RECORDING_STARTING,
            'recording_error' => null,
        ])->save();

        $egressId = $this->rooms->startRecording(new RecordingRequest(
            room: $session->room_name,
            layout: (string) config('live.recording.layout', 'speaker'),
            output: (string) config('live.recording.output', 'file'),
            filepath: $this->filepathFor($session),
            s3: (array) config('live.recording.s3', []),
        ));

        if ($egressId === null) {
            $session->forceFill([
                'recording_status' => ClassSession::RECORDING_FAILED,
                'recording_error' => 'The media server would not start the recording.',
            ])->save();

            throw new ClassroomException('The recording could not be started. The class is not being recorded.');
        }

        $session->forceFill([
            'recording_egress_id' => $egressId,
            'recording_status' => ClassSession::RECORDING_ON,
            'recording_started_at' => now(),
        ])->save();

        $this->announce($session);

        return $session;
    }

    /**
     * Stop recording.
     *
     * The file is not ready when this returns: the media server needs a moment
     * to finish writing it, and says so with a webhook. Until then the class
     * reads as `processing`, which is what the coach is shown.
     */
    public function stop(ClassSession $session): ClassSession
    {
        if (! $session->isRecording()) {
            return $session;
        }

        if ($session->recording_egress_id !== null) {
            $this->rooms->stopRecording($session->recording_egress_id);
        }

        $session->forceFill([
            'recording_status' => ClassSession::RECORDING_PROCESSING,
            'recording_ended_at' => now(),
        ])->save();

        $this->announce($session);

        return $session;
    }

    /**
     * The media server reporting a finished job.
     *
     * Idempotent: LiveKit retries a webhook it did not get a 2xx for, and a
     * second delivery must not make a second media asset.
     */
    public function complete(RecordingResult $result): ?ClassSession
    {
        $session = ClassSession::where('recording_egress_id', $result->egressId)->first();

        if ($session === null) {
            Log::warning('recording finished for an unknown class', ['egress' => $result->egressId]);

            return null;
        }

        if (! $result->isComplete()) {
            $session->forceFill([
                'recording_status' => ClassSession::RECORDING_FAILED,
                'recording_error' => Str::limit($result->error ?? 'The recording failed.', 240),
                'recording_ended_at' => $session->recording_ended_at ?? now(),
            ])->save();

            $this->announce($session);

            return $session;
        }

        if ($session->recording_media_asset_id === null) {
            $asset = $this->importFile($result);

            if ($asset === null) {
                $session->forceFill([
                    'recording_status' => ClassSession::RECORDING_FAILED,
                    'recording_error' => 'The recording finished but the file could not be found.',
                ])->save();

                $this->announce($session);

                return $session;
            }

            $session->recording_media_asset_id = $asset->id;
        }

        $session->forceFill([
            'recording_status' => ClassSession::RECORDING_READY,
            'recording_ended_at' => $session->recording_ended_at ?? now(),
            'recording_duration_ms' => $result->durationMs,
            'recording_error' => null,
        ])->save();

        $this->announce($session);

        return $session;
    }

    /**
     * Who may watch it back.
     *
     * The people who were entitled to be in the room: whoever taught it, the
     * school that ran it, and the learners it was for. Being on the roll counts
     * even for somebody who missed the class - catching up is most of the point
     * of recording one.
     */
    public function canWatch(ClassSession $session, User $user): bool
    {
        if ($session->coach_id === $user->id) {
            return true;
        }

        $session->loadMissing('group.school');

        if ($session->group?->school !== null
            && app(SchoolService::class)->isManager($session->group->school, $user)) {
            return true;
        }

        if ($session->participants()->where('user_id', $user->id)->exists()) {
            return true;
        }

        return (bool) $session->group?->students()->whereKey($user->id)->exists();
    }

    /**
     * A playable link, or a refusal.
     *
     * @return array{url:string,expires_in:int,duration_ms:?int,mime:?string}
     */
    public function playback(ClassSession $session, User $user): array
    {
        if (! $this->canWatch($session, $user)) {
            throw new ClassroomException('That recording is not yours to watch.', 403);
        }
        if (! $session->hasRecording()) {
            throw new ClassroomException('There is no recording of this class.', 404);
        }

        $minutes = 60;

        return [
            'url' => URL::temporarySignedRoute(
                'media.stream',
                now()->addMinutes($minutes),
                ['media' => $session->recording_media_asset_id],
            ),
            'expires_in' => $minutes * 60,
            'duration_ms' => $session->recording_duration_ms,
            'mime' => $session->recording?->mime,
        ];
    }

    /** The shape both clients render. */
    public function present(ClassSession $session): array
    {
        return [
            'status' => $session->recording_status,
            'is_recording' => $session->isRecording(),
            'is_ready' => $session->hasRecording(),
            'available' => $this->isAvailable(),
            'started_at' => $session->recording_started_at?->toIso8601String(),
            'ended_at' => $session->recording_ended_at?->toIso8601String(),
            'duration_ms' => $session->recording_duration_ms,
            'error' => $session->recording_error,
        ];
    }

    // ------------------------------------------------------------- private

    /**
     * Where the media server should write.
     *
     * Named after the class rather than the room, so a directory of files is
     * legible to a person looking at it without the database.
     */
    private function filepathFor(ClassSession $session): string
    {
        $directory = rtrim((string) config('live.recording.directory', '/recordings'), '/');
        $name = 'class-'.$session->id.'-'.$session->starts_at?->format('Ymd-Hi').'.mp4';

        return "{$directory}/{$name}";
    }

    /**
     * Turn the finished file into a media asset.
     *
     * For a file output the recording is on a directory this application can
     * read - usually the same volume mounted into both containers. For an S3
     * output it is wherever the bucket is, and the deployment is expected to
     * have pointed the default filesystem at that same bucket; see
     * docs/DEPLOYMENT.md.
     */
    private function importFile(RecordingResult $result): ?MediaAsset
    {
        if ($result->filename === null) {
            return null;
        }

        if ((string) config('live.recording.output') === 's3') {
            return MediaAsset::create([
                'disk' => config('filesystems.default'),
                'path' => ltrim($result->filename, '/'),
                'type' => 'video',
                'mime' => 'video/mp4',
                'bytes' => $result->bytes,
                'origin' => 'class_recording',
                'copyright_status' => 'owned',
            ]);
        }

        $directory = rtrim((string) config('live.recording.directory', '/recordings'), '/');
        $relative = ltrim(Str::after($result->filename, $directory), '/');

        $disk = Storage::disk('recordings');

        if (! $disk->exists($relative)) {
            Log::warning('recording file missing', ['expected' => $relative, 'reported' => $result->filename]);

            return null;
        }

        return MediaAsset::create([
            'disk' => 'recordings',
            'path' => $relative,
            'type' => 'video',
            'mime' => 'video/mp4',
            'bytes' => $result->bytes ?? $disk->size($relative),
            'origin' => 'class_recording',
            'copyright_status' => 'owned',
            'checksum' => hash_file('sha256', $disk->path($relative)),
        ]);
    }

    private function announce(ClassSession $session): void
    {
        event(new ClassroomEvent(
            $session->id,
            ClassroomEvent::RECORDING_CHANGED,
            ['recording' => $this->present($session)],
        ));
    }
}
