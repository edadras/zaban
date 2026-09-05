<?php

namespace App\Http\Controllers\Api\V1\Speech;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Api\Speech\StoreSpeechAttemptRequest;
use App\Http\Resources\Speech\SpeechAttemptResource;
use App\Jobs\Speech\ProcessSpeechAttempt;
use App\Models\SpeechAttempt;
use App\Services\Speech\SpeechConsentException;
use App\Services\Speech\SpeechRetentionService;
use Illuminate\Http\Request;

/**
 * Submitting a recording and reading its results.
 *
 * The upload returns immediately with a pending attempt; scoring happens on the
 * speech queue because transcription and forced alignment take far longer than
 * a request should.
 */
class SpeechAttemptController extends ApiController
{
    public function __construct(private SpeechRetentionService $retention) {}

    public function store(StoreSpeechAttemptRequest $request)
    {
        $userId = (int) $request->user()->id;

        try {
            $asset = $this->retention->storeRecording($userId, $request->file('audio'));
        } catch (SpeechConsentException $e) {
            return $this->fail('speech_consent_required', $e->getMessage(), 403, [
                'setting' => 'speech_consent_given',
            ]);
        }

        $attempt = SpeechAttempt::create([
            'user_id' => $userId,
            'exercise_id' => $request->integer('exercise_id') ?: null,
            'production_prompt_id' => $request->integer('production_prompt_id') ?: null,
            'pronunciation_item_id' => $request->integer('pronunciation_item_id') ?: null,
            'learning_session_id' => $request->integer('learning_session_id') ?: null,
            'media_asset_id' => $asset->id,
            'audio_deleted' => false,
            'audio_delete_after' => $this->retention->deleteAfterFor($userId),
            'expected_text' => $request->input('expected_text'),
            'duration_ms' => $request->integer('duration_ms') ?: null,
            'status' => 'pending',
        ]);

        ProcessSpeechAttempt::dispatch($attempt->id);

        return $this->created(new SpeechAttemptResource($attempt->refresh()));
    }

    public function show(Request $request, SpeechAttempt $attempt)
    {
        if ((int) $attempt->user_id !== (int) $request->user()->id) {
            return $this->fail('forbidden', 'This attempt belongs to another learner.', 403);
        }

        $attempt->load(['words' => fn ($q) => $q->orderBy('position'), 'words.phonemes' => fn ($q) => $q->orderBy('position')]);

        return $this->ok(new SpeechAttemptResource($attempt));
    }

    /** Recent attempts, newest first, without the per-word detail. */
    public function index(Request $request)
    {
        $attempts = SpeechAttempt::where('user_id', $request->user()->id)
            ->latest('id')
            ->paginate(min(50, max(1, (int) $request->integer('per_page', 20))));

        return $this->ok(SpeechAttemptResource::collection($attempts));
    }
}
