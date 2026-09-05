<?php

namespace Tests\Feature\Speech;

use App\Jobs\Speech\PurgeExpiredSpeechAudio;
use App\Models\MediaAsset;
use App\Models\Phoneme;
use App\Models\PronunciationError;
use App\Models\SpeechAttempt;
use App\Models\SpeechPhoneme;
use App\Models\SpeechWord;
use App\Services\Speech\SpeechRetentionService;
use Database\Seeders\PhonemeSeeder;
use Illuminate\Support\Facades\Storage;

/**
 * Spec 45: the recording and the measurements taken from it have separate
 * lifetimes. These tests exist to make that separation impossible to break by
 * accident.
 */
class SpeechRetentionTest extends SpeechTestCase
{
    public function test_expired_recordings_are_deleted_but_every_score_survives(): void
    {
        $this->seed(PhonemeSeeder::class);
        $user = $this->learner(retentionDays: 30);

        $attempt = $this->scoredAttempt($user->id, ['audio_delete_after' => now()->subDay()]);
        $path = $attempt->mediaAsset->path;

        $word = SpeechWord::create([
            'speech_attempt_id' => $attempt->id,
            'position' => 0,
            'expected_word' => 'think',
            'spoken_word' => 'sink',
            'outcome' => 'substituted',
            'accuracy_score' => 41.5,
        ]);
        $theta = Phoneme::where('ipa', 'θ')->firstOrFail();
        $ess = Phoneme::where('ipa', 's')->firstOrFail();
        SpeechPhoneme::create([
            'speech_word_id' => $word->id,
            'expected_phoneme_id' => $theta->id,
            'actual_phoneme_id' => $ess->id,
            'position' => 0,
            'accuracy_score' => 0,
            'is_error' => true,
        ]);
        PronunciationError::create([
            'user_id' => $user->id,
            'phoneme_id' => $theta->id,
            'occurrence_count' => 3,
            'attempt_count' => 10,
            'error_rate' => 0.3,
            'recent_error_rate' => 0.3,
            'example_words' => ['think'],
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        Storage::disk('local')->assertExists($path);

        app(PurgeExpiredSpeechAudio::class, ['limit' => 100])->handle(app(SpeechRetentionService::class));

        $attempt->refresh();

        // Gone: the audio and its asset row.
        Storage::disk('local')->assertMissing($path);
        $this->assertTrue($attempt->audio_deleted);
        $this->assertNull($attempt->media_asset_id);
        $this->assertNull($attempt->audio_delete_after);
        $this->assertSame(0, MediaAsset::whereNull('deleted_at')->count());

        // Kept: every derived measurement.
        $this->assertSame('scored', $attempt->status);
        $this->assertEqualsWithDelta(78.5, $attempt->overall_score, 0.001);
        $this->assertEqualsWithDelta(71.25, $attempt->pronunciation_score, 0.001);
        $this->assertEqualsWithDelta(82.0, $attempt->fluency_score, 0.001);
        $this->assertSame('I sink this is the third one.', $attempt->transcript);
        $this->assertSame(1, SpeechWord::where('speech_attempt_id', $attempt->id)->count());
        $this->assertSame(1, SpeechPhoneme::where('speech_word_id', $word->id)->count());
        $this->assertSame(1, PronunciationError::where('user_id', $user->id)->count());
    }

    public function test_recordings_inside_the_window_are_left_alone(): void
    {
        $user = $this->learner(retentionDays: 30);
        $attempt = $this->scoredAttempt($user->id, ['audio_delete_after' => now()->addDays(29)]);

        $deleted = app(SpeechRetentionService::class)->purgeExpired();

        $this->assertSame(0, $deleted);
        $this->assertFalse($attempt->refresh()->audio_deleted);
        Storage::disk('local')->assertExists($attempt->mediaAsset->path);
    }

    public function test_a_recording_with_no_expiry_gets_one_from_the_learners_setting(): void
    {
        $user = $this->learner(retentionDays: 7);
        $attempt = $this->scoredAttempt($user->id, ['audio_delete_after' => null]);
        $attempt->forceFill(['created_at' => now()->subDays(2)])->save();

        app(SpeechRetentionService::class)->backfillMissingExpiry();

        $this->assertEqualsWithDelta(
            now()->subDays(2)->addDays(7)->timestamp,
            $attempt->refresh()->audio_delete_after->timestamp,
            5,
        );
    }

    public function test_learner_can_delete_all_recordings_and_keep_their_scores(): void
    {
        $user = $this->learner();
        $one = $this->scoredAttempt($user->id);
        $two = $this->scoredAttempt($user->id);
        $otherUser = $this->learner();
        $other = $this->scoredAttempt($otherUser->id);

        $response = $this->actingAs($user)->deleteJson('/api/v1/speech/recordings');

        $response->assertOk()
            ->assertJsonPath('data.recordings_deleted', 2)
            ->assertJsonPath('data.scores_retained', true)
            ->assertJsonPath('data.pronunciation_profile_rows_deleted', null);

        foreach ([$one, $two] as $attempt) {
            $attempt->refresh();
            $this->assertTrue($attempt->audio_deleted);
            $this->assertNull($attempt->media_asset_id);
            $this->assertEqualsWithDelta(78.5, $attempt->overall_score, 0.001);
        }

        // Another learner's recordings are untouched.
        $this->assertFalse($other->refresh()->audio_deleted);
        Storage::disk('local')->assertExists($other->mediaAsset->path);
    }

    public function test_learner_can_also_erase_the_derived_profile_when_they_ask_for_it(): void
    {
        $this->seed(PhonemeSeeder::class);
        $user = $this->learner();
        $this->scoredAttempt($user->id);
        PronunciationError::create([
            'user_id' => $user->id,
            'phoneme_id' => Phoneme::where('ipa', 'θ')->value('id'),
            'occurrence_count' => 2,
            'attempt_count' => 8,
            'error_rate' => 0.25,
            'recent_error_rate' => 0.25,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        $this->actingAs($user)
            ->deleteJson('/api/v1/speech/recordings?include_analysis=1')
            ->assertOk()
            ->assertJsonPath('data.pronunciation_profile_rows_deleted', 1);

        $this->assertSame(0, PronunciationError::where('user_id', $user->id)->count());
    }

    public function test_a_single_recording_can_be_deleted_without_touching_the_attempt(): void
    {
        $user = $this->learner();
        $attempt = $this->scoredAttempt($user->id);
        $path = $attempt->mediaAsset->path;

        $this->actingAs($user)
            ->deleteJson("/api/v1/speech/attempts/{$attempt->id}/recording")
            ->assertOk()
            ->assertJsonPath('data.recording_deleted', true);

        Storage::disk('local')->assertMissing($path);
        $this->assertTrue($attempt->refresh()->audio_deleted);
        $this->assertEqualsWithDelta(78.5, $attempt->overall_score, 0.001);

        // Idempotent: asking twice is not an error and changes nothing.
        $this->actingAs($user)
            ->deleteJson("/api/v1/speech/attempts/{$attempt->id}/recording")
            ->assertOk()
            ->assertJsonPath('data.recording_deleted', false);
    }

    public function test_one_learner_cannot_delete_another_learners_recording(): void
    {
        $owner = $this->learner();
        $attempt = $this->scoredAttempt($owner->id);
        $intruder = $this->learner();

        $this->actingAs($intruder)
            ->deleteJson("/api/v1/speech/attempts/{$attempt->id}/recording")
            ->assertStatus(403);

        $this->assertFalse($attempt->refresh()->audio_deleted);
    }

    public function test_a_deleted_recording_reports_itself_as_unavailable(): void
    {
        $user = $this->learner();
        $attempt = $this->scoredAttempt($user->id);
        app(SpeechRetentionService::class)->deleteAudio($attempt);

        $this->actingAs($user)
            ->getJson("/api/v1/speech/attempts/{$attempt->id}")
            ->assertOk()
            ->assertJsonPath('data.audio.available', false)
            ->assertJsonPath('data.audio.deleted', true)
            ->assertJsonPath('data.scores.overall', 78.5);
    }
}
