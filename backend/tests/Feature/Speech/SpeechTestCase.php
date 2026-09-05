<?php

namespace Tests\Feature\Speech;

use App\Http\Controllers\Api\V1\Speech\PronunciationProfileController;
use App\Http\Controllers\Api\V1\Speech\SpeechAttemptController;
use App\Http\Controllers\Api\V1\Speech\SpeechRecordingController;
use App\Models\MediaAsset;
use App\Models\SpeechAttempt;
use App\Models\User;
use App\Models\UserSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Speech\Support\FakeSpeechProvider;
use Tests\TestCase;

/**
 * Shared rig for the speech tests.
 *
 * The speech routes are declared here rather than in routes/api.php because the
 * route file is owned elsewhere; the definitions mirror the ones documented in
 * app/Services/Speech/INTEGRATION.md exactly.
 */
abstract class SpeechTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Freeze on a whole second: it keeps the retention arithmetic exact, and
        // it sidesteps AiOrchestrator::finish() computing a negative duration_ms
        // (see the "Known defect" note in app/Services/Speech/INTEGRATION.md).
        $this->travelTo(now()->startOfSecond());

        FakeSpeechProvider::reset();
        Storage::fake('local');

        // The fake is registered the same way any provider is: through config.
        config()->set('ai.providers.fake-speech.driver', FakeSpeechProvider::class);
        config()->set('ai.chains.stt', ['fake-speech']);
        // No text provider: the feedback service must fall back to its
        // rules-based narrative rather than reaching the network.
        config()->set('ai.chains.text', []);

        $this->registerRoutes();
    }

    protected function registerRoutes(): void
    {
        Route::middleware('api')->prefix('api/v1/speech')->group(function () {
            Route::get('attempts', [SpeechAttemptController::class, 'index']);
            Route::post('attempts', [SpeechAttemptController::class, 'store']);
            Route::get('attempts/{attempt}', [SpeechAttemptController::class, 'show']);
            Route::delete('attempts/{attempt}/recording', [SpeechRecordingController::class, 'destroy']);
            Route::delete('recordings', [SpeechRecordingController::class, 'destroyAll']);
            Route::get('profile', [PronunciationProfileController::class, 'show']);
            Route::get('profile/drills', [PronunciationProfileController::class, 'drills']);
        });
    }

    protected function learner(bool $consent = true, int $retentionDays = 30): User
    {
        $user = User::factory()->create();
        UserSetting::create([
            'user_id' => $user->id,
            'speech_consent_given' => $consent,
            'speech_consent_at' => $consent ? now() : null,
            'speech_retention_days' => $retentionDays,
        ]);

        return $user;
    }

    /** A stored recording plus the media asset row that points at it. */
    protected function storedRecording(int $userId, string $contents = 'RIFFfake-wav-bytes'): MediaAsset
    {
        $path = "speech/{$userId}/".uniqid('rec_').'.wav';
        Storage::disk('local')->put($path, $contents);

        return MediaAsset::create([
            'disk' => 'local',
            'path' => $path,
            'type' => 'audio',
            'mime' => 'audio/wav',
            'bytes' => strlen($contents),
            'origin' => 'upload',
            'copyright_status' => 'owned',
        ]);
    }

    /** A scored attempt with a recording attached. */
    protected function scoredAttempt(int $userId, array $overrides = []): SpeechAttempt
    {
        $asset = $this->storedRecording($userId);

        return SpeechAttempt::create(array_merge([
            'user_id' => $userId,
            'media_asset_id' => $asset->id,
            'audio_deleted' => false,
            'audio_delete_after' => now()->addDays(30),
            'expected_text' => 'I think this is the third one.',
            'transcript' => 'I sink this is the third one.',
            'duration_ms' => 3000,
            'status' => 'scored',
            'overall_score' => 78.5,
            'pronunciation_score' => 71.25,
            'fluency_score' => 82.0,
            'completeness_score' => 100.0,
            'speech_rate_wpm' => 140.0,
            'pause_count' => 1,
            'total_pause_ms' => 320,
            'filler_count' => 0,
            'scored_at' => now(),
        ], $overrides));
    }
}
