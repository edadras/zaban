<?php

namespace Tests\Feature\Speech;

use App\AI\Support\SpeechResult;
use App\Models\Phoneme;
use App\Models\PronunciationError;
use App\Models\PronunciationItem;
use App\Models\SpeechAttempt;
use App\Models\SpeechPhoneme;
use App\Models\SpeechWord;
use App\Services\Speech\SpeechAnalysisService;
use Database\Seeders\PhonemeSeeder;
use Tests\Feature\Speech\Support\FakeSpeechProvider;

/**
 * The pipeline end to end, with a fake speech provider standing in for a real
 * STT engine and aligner.
 *
 * The load-bearing case is the second one: with no aligner configured, the
 * pronunciation score must be null and say why, and no phoneme statistics may be
 * invented from the transcript (spec 21).
 */
class SpeechAnalysisTest extends SpeechTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PhonemeSeeder::class);
    }

    private function attemptFor(int $userId, array $overrides = []): SpeechAttempt
    {
        $asset = $this->storedRecording($userId);

        return SpeechAttempt::create(array_merge([
            'user_id' => $userId,
            'media_asset_id' => $asset->id,
            'audio_deleted' => false,
            'audio_delete_after' => now()->addDays(30),
            'status' => 'pending',
        ], $overrides));
    }

    public function test_with_a_forced_aligner_the_pipeline_scores_words_and_phonemes(): void
    {
        $user = $this->learner();
        $item = PronunciationItem::where('text', 'think')->firstOrFail();
        $attempt = $this->attemptFor($user->id, [
            'expected_text' => 'think',
            'pronunciation_item_id' => $item->id,
        ]);

        FakeSpeechProvider::$transcription = new SpeechResult(
            ok: true,
            transcript: 'sink',
            words: [['word' => 'sink', 'start_ms' => 0, 'end_ms' => 500, 'confidence' => 0.88]],
            model: 'fake-whisper',
            durationMs: 600,
        );
        FakeSpeechProvider::$alignmentSupported = true;
        FakeSpeechProvider::$alignment = new SpeechResult(
            ok: true,
            transcript: 'think',
            phonemes: [
                ['word_index' => 0, 'phoneme' => 'S', 'start_ms' => 0, 'end_ms' => 120, 'score' => 0.10],
                ['word_index' => 0, 'phoneme' => 'IH', 'start_ms' => 120, 'end_ms' => 260, 'score' => 0.95],
                ['word_index' => 0, 'phoneme' => 'NG', 'start_ms' => 260, 'end_ms' => 400, 'score' => 0.95],
                ['word_index' => 0, 'phoneme' => 'K', 'start_ms' => 400, 'end_ms' => 500, 'score' => 0.95],
            ],
            model: 'fake-aligner',
        );

        app(SpeechAnalysisService::class)->analyse($attempt);
        $attempt->refresh();

        $this->assertSame('scored', $attempt->status);
        $this->assertSame('sink', $attempt->transcript);
        $this->assertSame('fake-aligner', $attempt->aligner);

        // /θ/ was realised as /s/: one substitution scored 0, three matches at 95.
        $this->assertEqualsWithDelta(71.25, $attempt->pronunciation_score, 0.01);

        $word = SpeechWord::where('speech_attempt_id', $attempt->id)->firstOrFail();
        $this->assertSame('think', $word->expected_word);
        $this->assertSame('sink', $word->spoken_word);
        $this->assertSame('substituted', $word->outcome);
        $this->assertEqualsWithDelta(71.25, $word->accuracy_score, 0.01);

        $phonemes = SpeechPhoneme::where('speech_word_id', $word->id)->orderBy('position')->get();
        $this->assertCount(4, $phonemes);
        $this->assertTrue((bool) $phonemes[0]->is_error);
        $this->assertSame(Phoneme::where('ipa', 'θ')->value('id'), $phonemes[0]->expected_phoneme_id);
        $this->assertSame(Phoneme::where('ipa', 's')->value('id'), $phonemes[0]->actual_phoneme_id);
        $this->assertFalse((bool) $phonemes[1]->is_error);

        // The profile picked up one opportunity for each expected sound.
        $theta = PronunciationError::where('user_id', $user->id)
            ->where('phoneme_id', Phoneme::where('ipa', 'θ')->value('id'))
            ->whereNull('substituted_phoneme_id')
            ->firstOrFail();
        $this->assertSame(1, (int) $theta->attempt_count);
        $this->assertSame(1, (int) $theta->occurrence_count);
        $this->assertSame(['think'], $theta->example_words);

        $this->assertDatabaseHas('pronunciation_errors', [
            'user_id' => $user->id,
            'phoneme_id' => Phoneme::where('ipa', 'θ')->value('id'),
            'substituted_phoneme_id' => Phoneme::where('ipa', 's')->value('id'),
            'occurrence_count' => 1,
        ]);

        $this->assertSame('θ', $attempt->feedback['observations']['phoneme_issues'][0]['phoneme']);
    }

    public function test_without_an_aligner_the_pronunciation_score_is_null_and_says_why(): void
    {
        $user = $this->learner();
        $attempt = $this->attemptFor($user->id, ['expected_text' => 'I go to the shop every morning']);

        FakeSpeechProvider::$transcription = new SpeechResult(
            ok: true,
            transcript: 'I um go to shop every morning',
            words: [
                ['word' => 'I', 'start_ms' => 0, 'end_ms' => 200, 'confidence' => 0.9],
                ['word' => 'um', 'start_ms' => 250, 'end_ms' => 450, 'confidence' => 0.5],
                ['word' => 'go', 'start_ms' => 500, 'end_ms' => 700, 'confidence' => 0.9],
                ['word' => 'to', 'start_ms' => 750, 'end_ms' => 900, 'confidence' => 0.9],
                ['word' => 'shop', 'start_ms' => 1600, 'end_ms' => 1900, 'confidence' => 0.9],
                ['word' => 'every', 'start_ms' => 1950, 'end_ms' => 2200, 'confidence' => 0.9],
                ['word' => 'morning', 'start_ms' => 2250, 'end_ms' => 2600, 'confidence' => 0.9],
            ],
            model: 'fake-whisper',
            durationMs: 2600,
        );
        // No aligner in the chain: this is the honesty case.
        FakeSpeechProvider::$alignmentSupported = false;

        app(SpeechAnalysisService::class)->analyse($attempt);
        $attempt->refresh();

        $this->assertSame('scored', $attempt->status);
        $this->assertNull($attempt->pronunciation_score);
        $this->assertNull($attempt->aligner);
        $this->assertSame(0, SpeechPhoneme::count());
        $this->assertSame(0, PronunciationError::where('user_id', $user->id)->count());

        $reason = $attempt->feedback['not_measured']['pronunciation'] ?? null;
        $this->assertNotNull($reason);
        $this->assertStringContainsString('align', strtolower($reason));

        // Everything that could be measured still was.
        $this->assertNotNull($attempt->overall_score);
        $this->assertEqualsWithDelta(161.54, $attempt->speech_rate_wpm, 0.01);
        $this->assertSame(1, (int) $attempt->pause_count);
        $this->assertSame(700, (int) $attempt->total_pause_ms);
        $this->assertSame(1, (int) $attempt->filler_count);
        $this->assertEqualsWithDelta(85.71, $attempt->completeness_score, 0.01);

        // The missing article was recorded for the learning engine.
        $this->assertDatabaseHas('learner_errors', [
            'user_id' => $user->id,
            'error_type' => 'article',
            'error_subtype' => 'omitted_article',
            'expected' => 'the',
        ]);

        $words = SpeechWord::where('speech_attempt_id', $attempt->id)->orderBy('position')->get();
        $this->assertSame(8, $words->count());
        $this->assertSame('omitted', $words->firstWhere('expected_word', 'the')->outcome);
        // No forced alignment means no per-word accuracy, not a zero.
        $this->assertNull($words->firstWhere('expected_word', 'go')->accuracy_score);
    }

    public function test_open_speech_scores_vocabulary_and_skips_what_needs_a_target_text(): void
    {
        $user = $this->learner();
        $attempt = $this->attemptFor($user->id);

        $transcript = 'yesterday my brother visited an old museum near the harbour and we walked '
            .'around the exhibition rooms discussing several unusual paintings before dinner';
        $words = [];
        $t = 0;
        foreach (explode(' ', $transcript) as $w) {
            $words[] = ['word' => $w, 'start_ms' => $t, 'end_ms' => $t + 300, 'confidence' => 0.9];
            $t += 350;
        }

        FakeSpeechProvider::$transcription = new SpeechResult(
            ok: true, transcript: $transcript, words: $words, model: 'fake-whisper', durationMs: $t,
        );

        app(SpeechAnalysisService::class)->analyse($attempt);
        $attempt->refresh();

        $this->assertSame('scored', $attempt->status);
        $this->assertNotNull($attempt->vocabulary_score);
        $this->assertNull($attempt->completeness_score);
        $this->assertNull($attempt->grammar_score);
        $this->assertNull($attempt->pronunciation_score);
        $this->assertArrayHasKey('completeness', $attempt->feedback['not_measured']);
        $this->assertArrayHasKey('grammar', $attempt->feedback['not_measured']);
        $this->assertStringContainsString('open speech', $attempt->feedback['not_measured']['pronunciation']);
    }

    public function test_a_failed_transcription_marks_the_attempt_failed_without_scores(): void
    {
        $user = $this->learner();
        $attempt = $this->attemptFor($user->id, ['expected_text' => 'anything']);

        FakeSpeechProvider::$transcription = SpeechResult::failure('Audio was unreadable.');

        app(SpeechAnalysisService::class)->analyse($attempt);
        $attempt->refresh();

        $this->assertSame('failed', $attempt->status);
        $this->assertNotNull($attempt->error);
        $this->assertNull($attempt->overall_score);
        $this->assertSame(0, SpeechWord::count());
    }

    public function test_a_deleted_recording_cannot_be_rescored(): void
    {
        $user = $this->learner();
        $attempt = $this->attemptFor($user->id, ['expected_text' => 'anything']);
        $attempt->forceFill(['audio_deleted' => true, 'media_asset_id' => null])->save();

        app(SpeechAnalysisService::class)->analyse($attempt);

        $this->assertSame('failed', $attempt->refresh()->status);
        $this->assertStringContainsString('deleted', $attempt->error);
        $this->assertEmpty(FakeSpeechProvider::$calls);
    }

    public function test_the_attempts_list_only_shows_the_learners_own_attempts(): void
    {
        $user = $this->learner();
        $other = $this->learner();
        $this->attemptFor($user->id, ['expected_text' => 'one']);
        $this->attemptFor($user->id, ['expected_text' => 'two']);
        $this->attemptFor($other->id, ['expected_text' => 'theirs']);

        $response = $this->actingAs($user)->getJson('/api/v1/speech/attempts');

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.expected_text', 'two');
    }

    public function test_the_results_endpoint_returns_words_and_phonemes(): void
    {
        $user = $this->learner();
        $item = PronunciationItem::where('text', 'think')->firstOrFail();
        $attempt = $this->attemptFor($user->id, [
            'expected_text' => 'think',
            'pronunciation_item_id' => $item->id,
        ]);

        FakeSpeechProvider::$transcription = new SpeechResult(
            ok: true,
            transcript: 'think',
            words: [['word' => 'think', 'start_ms' => 0, 'end_ms' => 500, 'confidence' => 0.95]],
            model: 'fake-whisper',
            durationMs: 600,
        );
        FakeSpeechProvider::$alignmentSupported = true;
        FakeSpeechProvider::$alignment = new SpeechResult(
            ok: true,
            phonemes: [
                ['word_index' => 0, 'phoneme' => 'TH', 'start_ms' => 0, 'end_ms' => 120, 'score' => 0.40],
                ['word_index' => 0, 'phoneme' => 'IH', 'start_ms' => 120, 'end_ms' => 260, 'score' => 0.90],
                ['word_index' => 0, 'phoneme' => 'NG', 'start_ms' => 260, 'end_ms' => 400, 'score' => 0.90],
                ['word_index' => 0, 'phoneme' => 'K', 'start_ms' => 400, 'end_ms' => 500, 'score' => 0.90],
            ],
            model: 'fake-aligner',
        );

        app(SpeechAnalysisService::class)->analyse($attempt);

        $response = $this->actingAs($user)->getJson("/api/v1/speech/attempts/{$attempt->id}");

        $response->assertOk()
            ->assertJsonPath('data.status', 'scored')
            ->assertJsonPath('data.engines.phoneme_scoring', true)
            // Recognised correctly but articulated poorly: that is what a weak
            // phoneme score is for.
            ->assertJsonPath('data.words.0.outcome', 'mispronounced')
            ->assertJsonPath('data.words.0.phonemes.0.expected', 'θ')
            ->assertJsonPath('data.words.0.phonemes.0.is_error', true)
            ->assertJsonPath('data.words.0.phonemes.1.is_error', false);
    }
}
