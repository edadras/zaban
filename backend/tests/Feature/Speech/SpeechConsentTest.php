<?php

namespace Tests\Feature\Speech;

use App\Jobs\Speech\ProcessSpeechAttempt;
use App\Models\MediaAsset;
use App\Models\SpeechAttempt;
use App\Services\Speech\SpeechConsentException;
use App\Services\Speech\SpeechRetentionService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

class SpeechConsentTest extends SpeechTestCase
{
    public function test_upload_is_refused_without_consent_and_nothing_is_stored(): void
    {
        $user = $this->learner(consent: false);

        $response = $this->actingAs($user)->postJson('/api/v1/speech/attempts', [
            'audio' => UploadedFile::fake()->create('take.wav', 64, 'audio/wav'),
            'expected_text' => 'I think this is the third one.',
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('error.code', 'speech_consent_required')
            ->assertJsonPath('error.details.setting', 'speech_consent_given');

        $this->assertSame(0, SpeechAttempt::count());
        $this->assertSame(0, MediaAsset::count());
        $this->assertEmpty(Storage::disk('local')->allFiles());
    }

    public function test_service_refuses_to_store_audio_without_consent(): void
    {
        $user = $this->learner(consent: false);

        $this->expectException(SpeechConsentException::class);

        app(SpeechRetentionService::class)->storeRecording(
            $user->id,
            UploadedFile::fake()->create('take.wav', 8, 'audio/wav'),
        );
    }

    public function test_missing_settings_row_counts_as_no_consent(): void
    {
        $user = \App\Models\User::factory()->create();

        $this->assertFalse(app(SpeechRetentionService::class)->hasConsent($user->id));
    }

    public function test_upload_with_consent_stores_the_recording_and_queues_scoring(): void
    {
        Queue::fake();
        $user = $this->learner(consent: true, retentionDays: 14);

        $response = $this->actingAs($user)->postJson('/api/v1/speech/attempts', [
            'audio' => UploadedFile::fake()->create('take.wav', 64, 'audio/wav'),
            'expected_text' => 'I think this is the third one.',
            'duration_ms' => 2400,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.audio.available', true);

        $attempt = SpeechAttempt::firstOrFail();
        $this->assertSame($user->id, $attempt->user_id);
        $this->assertNotNull($attempt->media_asset_id);

        // The retention clock starts at upload, from the learner's own setting.
        $this->assertEqualsWithDelta(
            now()->addDays(14)->timestamp,
            $attempt->audio_delete_after->timestamp,
            5,
        );

        Storage::disk('local')->assertExists($attempt->mediaAsset->path);
        Queue::assertPushedOn('speech', ProcessSpeechAttempt::class);
    }

    public function test_non_audio_uploads_are_rejected(): void
    {
        $user = $this->learner();

        $this->actingAs($user)->postJson('/api/v1/speech/attempts', [
            'audio' => UploadedFile::fake()->create('notes.txt', 4, 'text/plain'),
        ])->assertStatus(422)->assertJsonValidationErrors('audio');
    }
}
