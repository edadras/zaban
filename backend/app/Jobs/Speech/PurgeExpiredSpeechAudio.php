<?php

namespace App\Jobs\Speech;

use App\Services\Speech\SpeechRetentionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Enforces the learner's audio retention window (spec 45).
 *
 * Recordings expire; the scores, word rows and phoneme statistics derived from
 * them do not. Retention is therefore a deletion of the audio only, and the
 * attempt keeps everything that makes it useful for teaching.
 */
class PurgeExpiredSpeechAudio implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public int $limit = 500)
    {
        $this->onQueue('speech');
    }

    public function handle(SpeechRetentionService $retention): void
    {
        $backfilled = $retention->backfillMissingExpiry($this->limit);
        $deleted = $retention->purgeExpired($this->limit);

        if ($deleted || $backfilled) {
            Log::info('speech.retention.purged', [
                'recordings_deleted' => $deleted,
                'expiry_backfilled' => $backfilled,
            ]);
        }
    }
}
