<?php

namespace App\Jobs\Speech;

use App\Events\AttemptScored;
use App\Models\SpeechAttempt;
use App\Services\Speech\SpeechAnalysisService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Runs the speech pipeline off the request cycle.
 *
 * Transcription and forced alignment are heavy and slow; the learner gets an
 * attempt id straight away and polls for the result. Its own queue keeps a
 * backlog of recordings from delaying short interactive jobs.
 */
class ProcessSpeechAttempt implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 600;

    public function __construct(public int $speechAttemptId)
    {
        $this->onQueue('speech');
    }

    /** @return array<int,int> seconds between retries */
    public function backoff(): array
    {
        return [10, 60];
    }

    public function middleware(): array
    {
        // Two workers scoring the same attempt would fight over its word rows.
        return [(new WithoutOverlapping('speech-attempt:'.$this->speechAttemptId))->expireAfter(900)];
    }

    public function handle(SpeechAnalysisService $analysis): void
    {
        $attempt = SpeechAttempt::find($this->speechAttemptId);
        if (! $attempt) {
            return;
        }

        $analysis->analyse($attempt);

        // The learner is waiting on this one: scoring a recording is a
        // transcription, an alignment and a model call. Polling still works and
        // is still the fallback; this is what lets the client stop.
        AttemptScored::dispatch(
            $attempt->user_id, 'speech', $attempt->id, $attempt->fresh()->status,
        );
    }

    public function failed(\Throwable $e): void
    {
        SpeechAttempt::where('id', $this->speechAttemptId)
            ->whereIn('status', ['pending', 'processing'])
            ->update([
                'status' => 'failed',
                'error' => mb_substr($e->getMessage(), 0, 1000),
                'updated_at' => now(),
            ]);

        $attempt = SpeechAttempt::find($this->speechAttemptId);
        if ($attempt !== null) {
            // A failure is news too. Without it the client waits for a score
            // that is never coming.
            AttemptScored::dispatch($attempt->user_id, 'speech', $attempt->id, 'failed');
        }
    }
}
