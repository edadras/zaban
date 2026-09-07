<?php

namespace App\Jobs;

use App\Models\AssignmentSubmission;
use App\Services\Classroom\HomeworkMarker;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The machine's first pass at one piece of homework.
 *
 * Queued because marking a paragraph means an AI round trip, and a learner
 * pressing "hand in" should not wait for it. Nothing here returns work to
 * anybody: it writes what the machine thought next to the coach's own columns,
 * and the coach releases.
 */
class MarkAssignmentSubmission implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public readonly int $submissionId) {}

    public function handle(HomeworkMarker $marker): void
    {
        $submission = AssignmentSubmission::with([
            'assignment.items', 'writingAttempt', 'speechAttempt',
        ])->find($this->submissionId);

        if ($submission === null) {
            return;
        }

        /*
         * A spoken piece is analysed by the speech pipeline on its own
         * schedule, so this job can arrive first. Waiting is better than
         * recording a failure somebody would have to be told to forget.
         */
        if ($marker->isWaiting($submission)) {
            $this->release(30);

            return;
        }

        $marker->mark($submission);
    }
}
