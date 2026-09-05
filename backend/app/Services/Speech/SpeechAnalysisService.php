<?php

namespace App\Services\Speech;

use App\AI\AiOrchestrator;
use App\AI\Support\SpeechRequest;
use App\Models\Language;
use App\Models\LearnerProfile;
use App\Models\SpeechAttempt;
use App\Models\SpeechWord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The speech pipeline: recording in, measured and explained attempt out (spec 20-21).
 *
 * Order matters. Transcription establishes what was said; the word diff against
 * the expected text establishes what was meant to be said; forced alignment - and
 * only forced alignment - establishes how it was pronounced. When the last stage
 * is unavailable the attempt is still scored on everything the first two
 * measured, and the pronunciation score is left null with the reason attached.
 * A number invented from a transcript would be indistinguishable from a real one
 * to the learner, which is precisely why it must not exist.
 */
class SpeechAnalysisService
{
    public function __construct(
        private AiOrchestrator $ai,
        private TextTokeniser $tokeniser,
        private WordAligner $wordAligner,
        private FluencyAnalyser $fluency,
        private PhonemeScorer $phonemeScorer,
        private SpeechScorer $scorer,
        private TranscriptErrorDetector $detector,
        private PronunciationProfileService $profile,
        private SpeechFeedbackService $feedback,
        private SpeechRetentionService $retention,
    ) {}

    public function analyse(SpeechAttempt $attempt): SpeechAttempt
    {
        $path = $this->retention->pathFor($attempt);
        if ($path === null) {
            return $this->fail($attempt, $attempt->audio_deleted
                ? 'The recording has been deleted and cannot be re-scored.'
                : 'The recording could not be read from storage.');
        }

        $attempt->forceFill(['status' => 'processing', 'error' => null])->save();

        $languageId = $this->languageIdFor($attempt);
        $notMeasured = [];

        $stt = $this->ai->transcribe(new SpeechRequest(
            audioPath: $path,
            expectedText: $attempt->expected_text,
            userId: $attempt->user_id,
            metadata: ['speech_attempt_id' => $attempt->id],
        ));

        if (! $stt->ok) {
            return $this->fail($attempt, $stt->error ?? 'Transcription failed.');
        }

        $expectedTokens = $this->tokeniser->tokenise($attempt->expected_text);
        $spokenTokens = $stt->words !== []
            ? $this->tokeniser->fromProviderWords($stt->words)
            : $this->withoutTimings($this->tokeniser->tokenise($stt->transcript));

        $wordRows = $this->wordAligner->align($expectedTokens, $spokenTokens);
        $words = $this->persistWords($attempt, $wordRows);

        $durationMs = $stt->durationMs ?? $attempt->duration_ms;
        $fluencyMetrics = $this->fluency->measure($spokenTokens, $durationMs);
        $fluencyScore = $this->fluency->score($fluencyMetrics, count($spokenTokens));
        if ($fluencyMetrics['reason']) {
            $notMeasured['fluency'] = $fluencyMetrics['reason'];
        }

        // --- pronunciation: forced alignment or nothing ------------------
        $phonemeResult = [
            'scored' => false, 'pronunciation_score' => null, 'word_accuracy' => [],
            'word_errors' => [], 'observations' => [], 'issues' => [], 'unresolved_labels' => [],
        ];
        $alignerName = null;

        if ($expectedTokens === []) {
            $notMeasured['pronunciation'] = 'Pronunciation needs a target text to align against; this was open speech.';
        } else {
            $alignment = $this->ai->align(new SpeechRequest(
                audioPath: $path,
                expectedText: $attempt->expected_text,
                userId: $attempt->user_id,
                metadata: ['speech_attempt_id' => $attempt->id],
            ));

            if ($alignment->ok && $alignment->phonemes !== []) {
                $alignerName = $alignment->model;
                $phonemeResult = $this->phonemeScorer->score(
                    $attempt,
                    $expectedTokens,
                    $this->indexWordsByExpectedPosition($wordRows, $words),
                    $alignment->phonemes,
                    $languageId,
                );
                if (! $phonemeResult['scored']) {
                    $notMeasured['pronunciation'] = 'The aligner returned no phonemes that could be matched to this attempt.';
                }
                if ($phonemeResult['unresolved_labels'] !== []) {
                    Log::info('speech.phonemes.unresolved', [
                        'speech_attempt_id' => $attempt->id,
                        'labels' => $phonemeResult['unresolved_labels'],
                    ]);
                }
            } else {
                $notMeasured['pronunciation'] = $alignment->error
                    ?: 'No forced aligner was available, so pronunciation was not scored.';
            }
        }

        $this->applyPhonemeOutcomes($words, $phonemeResult);

        // --- language-level errors ---------------------------------------
        $recorded = $expectedTokens !== []
            ? $this->detector->record((int) $attempt->user_id, $wordRows)
            : ['findings' => [], 'errors' => []];

        // --- scores -------------------------------------------------------
        $omitted = count(array_filter($wordRows, fn ($r) => $r['outcome'] === WordAligner::OMITTED));
        $completeness = $this->scorer->completeness(count($expectedTokens), $omitted);
        if ($completeness === null) {
            $notMeasured['completeness'] = 'Completeness needs a target text; this was open speech.';
        }

        $grammar = $this->scorer->grammar(count($expectedTokens), $recorded['findings']);
        if ($grammar === null) {
            $notMeasured['grammar'] = 'Grammar is scored as deviation from a target text, which this attempt has none of.';
        }

        $vocabulary = $this->scorer->vocabulary($spokenTokens, $expectedTokens !== []);
        if ($vocabulary['reason']) {
            $notMeasured['vocabulary'] = $vocabulary['reason'];
        }

        $components = [
            'pronunciation' => $phonemeResult['pronunciation_score'],
            'fluency' => $fluencyScore,
            'completeness' => $completeness,
            'grammar' => $grammar,
            'vocabulary' => $vocabulary['score'],
        ];

        $measurement = $components + [
            'overall_score' => $this->scorer->overall($components),
            'pronunciation_score' => $components['pronunciation'],
            'fluency_score' => $components['fluency'],
            'grammar_score' => $components['grammar'],
            'vocabulary_score' => $components['vocabulary'],
            'completeness_score' => $components['completeness'],
            'speech_rate_wpm' => $fluencyMetrics['speech_rate_wpm'],
            'pause_count' => $fluencyMetrics['pause_count'],
            'total_pause_ms' => $fluencyMetrics['total_pause_ms'],
            'filler_count' => $fluencyMetrics['filler_count'],
            'articulation_rate_wpm' => $fluencyMetrics['articulation_rate_wpm'],
            'word_rows' => $wordRows,
            'phoneme_issues' => $phonemeResult['issues'],
            'findings' => $recorded['findings'],
            'not_measured' => $notMeasured,
        ];

        $attempt->forceFill([
            'transcript' => $stt->transcript,
            'duration_ms' => $durationMs ?? $fluencyMetrics['speaking_ms'],
            'status' => 'scored',
            'error' => null,
            'overall_score' => $measurement['overall_score'],
            'pronunciation_score' => $components['pronunciation'],
            'fluency_score' => $components['fluency'],
            'grammar_score' => $components['grammar'],
            'vocabulary_score' => $components['vocabulary'],
            'completeness_score' => $components['completeness'],
            'speech_rate_wpm' => $fluencyMetrics['speech_rate_wpm'],
            'pause_count' => $fluencyMetrics['pause_count'],
            'total_pause_ms' => $fluencyMetrics['total_pause_ms'],
            'filler_count' => $fluencyMetrics['filler_count'],
            'stt_provider' => $this->trim($stt->model, 48),
            'aligner' => $this->trim($alignerName, 48),
            'scored_at' => now(),
        ])->save();

        // The profile is fed only from real phoneme measurements, so a run
        // without an aligner leaves the learner's history untouched rather than
        // polluting it with guesses.
        if ($phonemeResult['observations'] !== []) {
            $this->profile->record((int) $attempt->user_id, $phonemeResult['observations']);
        }

        $attempt->forceFill(['feedback' => $this->feedback->build($attempt, $measurement)])->save();

        return $attempt->refresh();
    }

    /** @return array<int,SpeechWord> keyed by row position */
    private function persistWords(SpeechAttempt $attempt, array $wordRows): array
    {
        return DB::transaction(function () use ($attempt, $wordRows) {
            // Re-scoring an attempt replaces its previous rows; the phoneme rows
            // hang off these and go with them.
            $attempt->words()->delete();

            $out = [];
            foreach ($wordRows as $row) {
                $out[$row['position']] = SpeechWord::create([
                    'speech_attempt_id' => $attempt->id,
                    'position' => $row['position'],
                    'expected_word' => $row['expected_word'],
                    'spoken_word' => $row['spoken_word'],
                    'start_ms' => $row['start_ms'],
                    'end_ms' => $row['end_ms'],
                    'confidence' => $row['confidence'],
                    'outcome' => $row['outcome'],
                ]);
            }

            return $out;
        });
    }

    /**
     * Aligners index their phonemes by position in the expected text, so the
     * word rows have to be reachable by that index rather than by row position.
     *
     * @param  array<int,SpeechWord>  $words
     * @return array<int,SpeechWord>
     */
    private function indexWordsByExpectedPosition(array $wordRows, array $words): array
    {
        $out = [];
        foreach ($wordRows as $row) {
            if ($row['expected_index'] !== null && isset($words[$row['position']])) {
                $out[$row['expected_index']] = $words[$row['position']];
            }
        }

        return $out;
    }

    /**
     * A word the transcriber recognised can still be mispronounced; that only
     * becomes visible once phoneme scores exist.
     *
     * @param  array<int,SpeechWord>  $words
     */
    private function applyPhonemeOutcomes(array $words, array $phonemeResult): void
    {
        foreach ($words as $word) {
            $accuracy = $phonemeResult['word_accuracy'][$word->id] ?? null;
            $errors = $phonemeResult['word_errors'][$word->id] ?? 0;
            if ($accuracy === null && $errors === 0) {
                continue;
            }

            $update = ['accuracy_score' => $accuracy];
            if ($errors > 0 && $word->outcome === WordAligner::CORRECT) {
                $update['outcome'] = WordAligner::MISPRONOUNCED;
            }
            $word->forceFill($update)->save();
        }
    }

    /** @param array<int,array{raw:string,norm:string}> $tokens */
    private function withoutTimings(array $tokens): array
    {
        return array_map(
            fn ($t) => $t + ['start_ms' => null, 'end_ms' => null, 'confidence' => null],
            $tokens,
        );
    }

    private function languageIdFor(SpeechAttempt $attempt): int
    {
        $languageId = LearnerProfile::where('user_id', $attempt->user_id)->value('language_id');

        return (int) ($languageId ?? Language::where('code', 'en')->value('id') ?? 0);
    }

    private function fail(SpeechAttempt $attempt, string $error): SpeechAttempt
    {
        Log::warning('speech.analysis.failed', ['speech_attempt_id' => $attempt->id, 'error' => $error]);
        $attempt->forceFill(['status' => 'failed', 'error' => $error])->save();

        return $attempt;
    }

    private function trim(?string $value, int $max): ?string
    {
        return $value === null ? null : mb_substr($value, 0, $max);
    }
}
