<?php

namespace App\Services\Speech;

use App\Models\MediaAsset;
use App\Models\SpeechAttempt;
use App\Models\UserSetting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Consent, storage and retention for learner recordings (spec 45).
 *
 * The design rule the whole class exists to serve: the recording and the
 * measurements taken from it have separate lifetimes. Audio is the sensitive
 * part and expires; the scores, the word rows and the phoneme statistics are
 * what the learner is actually paying for and stay.
 */
class SpeechRetentionService
{
    /** Used when a learner has no settings row yet. */
    public const DEFAULT_RETENTION_DAYS = 90;

    /** Where recordings live. Private disk: nothing under here is publicly reachable. */
    public const DISK = 'local';

    public function hasConsent(int $userId): bool
    {
        return (bool) UserSetting::where('user_id', $userId)->value('speech_consent_given');
    }

    public function retentionDays(int $userId): int
    {
        $days = UserSetting::where('user_id', $userId)->value('speech_retention_days');

        return $days !== null ? (int) $days : self::DEFAULT_RETENTION_DAYS;
    }

    public function deleteAfterFor(int $userId): \Illuminate\Support\Carbon
    {
        return now()->addDays($this->retentionDays($userId));
    }

    /**
     * Store an upload and return its media asset.
     *
     * @throws SpeechConsentException when the learner has not consented
     */
    public function storeRecording(int $userId, UploadedFile $file): MediaAsset
    {
        if (! $this->hasConsent($userId)) {
            throw new SpeechConsentException(
                'This account has not given consent to store voice recordings.',
            );
        }

        $extension = strtolower($file->getClientOriginalExtension() ?: $file->guessExtension() ?: 'wav');
        $name = Str::uuid()->toString().'.'.$extension;
        $path = $file->storeAs(sprintf('speech/%d', $userId), $name, ['disk' => self::DISK]);

        return MediaAsset::create([
            'disk' => self::DISK,
            'path' => $path,
            'type' => 'audio',
            'mime' => $file->getClientMimeType() ?: 'audio/wav',
            'bytes' => Storage::disk(self::DISK)->size($path),
            'origin' => 'upload',
            'copyright_status' => 'owned',
            'metadata' => ['user_id' => $userId, 'kind' => 'speech_attempt'],
        ]);
    }

    /** Absolute path of an attempt's recording, or null once it is gone. */
    public function pathFor(SpeechAttempt $attempt): ?string
    {
        if ($attempt->audio_deleted || ! $attempt->media_asset_id) {
            return null;
        }

        $asset = $attempt->mediaAsset;
        if (! $asset) {
            return null;
        }

        $disk = Storage::disk($asset->disk ?: self::DISK);

        return $disk->exists($asset->path) ? $disk->path($asset->path) : null;
    }

    /**
     * Delete one attempt's audio, keeping every derived measurement.
     *
     * Idempotent: an attempt whose audio is already gone is left alone.
     */
    public function deleteAudio(SpeechAttempt $attempt): bool
    {
        if ($attempt->audio_deleted && ! $attempt->media_asset_id) {
            return false;
        }

        $asset = $attempt->mediaAsset;
        if ($asset) {
            try {
                Storage::disk($asset->disk ?: self::DISK)->delete($asset->path);
            } catch (\Throwable $e) {
                Log::warning('speech.audio.delete_failed', [
                    'speech_attempt_id' => $attempt->id,
                    'error' => $e->getMessage(),
                ]);
            }
            $asset->delete();
        }

        $attempt->forceFill([
            'media_asset_id' => null,
            'audio_deleted' => true,
            'audio_delete_after' => null,
        ])->save();

        return true;
    }

    /**
     * Delete recordings whose retention window has closed.
     *
     * @return int number of recordings removed
     */
    public function purgeExpired(int $limit = 500): int
    {
        $deleted = 0;

        SpeechAttempt::query()
            ->where('audio_deleted', false)
            ->whereNotNull('media_asset_id')
            ->whereNotNull('audio_delete_after')
            ->where('audio_delete_after', '<=', now())
            ->orderBy('audio_delete_after')
            ->limit($limit)
            ->get()
            ->each(function (SpeechAttempt $attempt) use (&$deleted) {
                if ($this->deleteAudio($attempt)) {
                    $deleted++;
                }
            });

        return $deleted;
    }

    /**
     * Defensive sweep for rows that never got a delete-after stamp - a recording
     * with no expiry set is exactly the failure mode retention exists to prevent.
     */
    public function backfillMissingExpiry(int $limit = 500): int
    {
        $rows = SpeechAttempt::query()
            ->where('audio_deleted', false)
            ->whereNotNull('media_asset_id')
            ->whereNull('audio_delete_after')
            ->limit($limit)
            ->get();

        foreach ($rows as $attempt) {
            $attempt->forceFill([
                'audio_delete_after' => $attempt->created_at
                    ? $attempt->created_at->copy()->addDays($this->retentionDays((int) $attempt->user_id))
                    : $this->deleteAfterFor((int) $attempt->user_id),
            ])->save();
        }

        return $rows->count();
    }

    /**
     * "Delete my recordings": every stored recording for one learner goes,
     * scores and phoneme statistics stay.
     *
     * @return int number of recordings removed
     */
    public function deleteRecordingsFor(int $userId): int
    {
        $deleted = 0;

        SpeechAttempt::query()
            ->where('user_id', $userId)
            ->where(fn ($q) => $q->where('audio_deleted', false)->orWhereNotNull('media_asset_id'))
            ->chunkById(200, function ($attempts) use (&$deleted) {
                foreach ($attempts as $attempt) {
                    if ($this->deleteAudio($attempt)) {
                        $deleted++;
                    }
                }
            });

        return $deleted;
    }

    /**
     * The stronger request: drop the derived pronunciation statistics too.
     * Kept separate from deleteRecordingsFor because most learners want the
     * audio gone and their progress kept.
     */
    public function deleteAnalysisFor(int $userId): int
    {
        return DB::table('pronunciation_errors')->where('user_id', $userId)->delete();
    }
}
