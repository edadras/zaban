<?php

namespace App\Jobs\Writing;

use App\Events\AttemptScored;
use App\Models\WritingAttempt;
use App\Services\Writing\HandwritingRecogniser;
use App\Services\Writing\WritingAnalysisService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Drives one writing attempt as far as it can go without the learner.
 *
 * A typed attempt goes straight to marking. A photographed one stops after
 * recognition and waits: the reading has to be confirmed before it is marked,
 * so the job ends there and is dispatched again once the learner accepts the
 * text. That pause is the point, not an inconvenience to design around.
 */
class ProcessWritingAttempt implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    public function __construct(public int $writingAttemptId)
    {
        $this->onQueue('writing');
    }

    /** @return array<int,int> seconds between retries */
    public function backoff(): array
    {
        return [10, 60];
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('writing-attempt:'.$this->writingAttemptId))->expireAfter(600)];
    }

    public function handle(
        HandwritingRecogniser $recogniser,
        WritingAnalysisService $analysis,
    ): void {
        $attempt = WritingAttempt::find($this->writingAttemptId);

        if (! $attempt) {
            return;
        }

        if ($attempt->source === WritingAttempt::SOURCE_PHOTO && ! $attempt->text_confirmed) {
            // Read the page, then stop. Marking waits for the learner.
            $recogniser->recognise($attempt);

            return;
        }

        $analysis->analyse($attempt);

        AttemptScored::dispatch(
            $attempt->user_id, 'writing', $attempt->id, $attempt->fresh()->status,
        );
    }

    public function failed(\Throwable $e): void
    {
        WritingAttempt::where('id', $this->writingAttemptId)
            ->whereNotIn('status', [WritingAttempt::STATUS_SCORED])
            ->update([
                'status' => WritingAttempt::STATUS_FAILED,
                'error' => 'The attempt could not be processed: '.$e->getMessage(),
            ]);

        $attempt = WritingAttempt::find($this->writingAttemptId);
        if ($attempt !== null) {
            AttemptScored::dispatch(
                $attempt->user_id, 'writing', $attempt->id, WritingAttempt::STATUS_FAILED,
            );
        }
    }
}
