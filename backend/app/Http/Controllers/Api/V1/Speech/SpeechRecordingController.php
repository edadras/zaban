<?php

namespace App\Http\Controllers\Api\V1\Speech;

use App\Http\Controllers\Api\V1\ApiController;
use App\Models\SpeechAttempt;
use App\Services\Speech\SpeechRetentionService;
use Illuminate\Http\Request;

/**
 * "Delete my recordings" (spec 45).
 *
 * Two separate doors on purpose. Deleting recordings removes the audio and keeps
 * the learner's scores and pronunciation profile; deleting the analysis as well
 * is a second, explicit request, because most people asking for the first do not
 * want to lose their progress.
 */
class SpeechRecordingController extends ApiController
{
    public function __construct(private SpeechRetentionService $retention) {}

    /** Delete every stored recording for the current learner. */
    public function destroyAll(Request $request)
    {
        $userId = (int) $request->user()->id;
        $deleted = $this->retention->deleteRecordingsFor($userId);

        $analysisDeleted = null;
        if ($request->boolean('include_analysis')) {
            $analysisDeleted = $this->retention->deleteAnalysisFor($userId);
        }

        return $this->ok([
            'recordings_deleted' => $deleted,
            'pronunciation_profile_rows_deleted' => $analysisDeleted,
            'scores_retained' => true,
        ]);
    }

    /** Delete one attempt's recording, keeping its scores. */
    public function destroy(Request $request, SpeechAttempt $attempt)
    {
        if ((int) $attempt->user_id !== (int) $request->user()->id) {
            return $this->fail('forbidden', 'This attempt belongs to another learner.', 403);
        }

        $deleted = $this->retention->deleteAudio($attempt);

        return $this->ok([
            'speech_attempt_id' => $attempt->id,
            'recording_deleted' => $deleted,
            'scores_retained' => true,
        ]);
    }
}
