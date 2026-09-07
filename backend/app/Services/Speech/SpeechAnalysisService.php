<?php

namespace App\Services\Speech;

use App\AI\AiOrchestrator;
use App\AI\Support\SpeechRequest;
use App\Models\Language;
use App\Models\LearnerProfile;
use App\Models\SpeechAttempt;
use App\Models\SpeechWord;
use App\Services\Learning\ProgressService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The speech pipeline: recording in, measured and explained attempt out.
 *
 * Order: transcription → word diff → fluency → optional forced alignment →
 * scores. When alignment / timings are missing, [SpeechAiCoachService] fills
 * score gaps and writes coaching from the transcript (and target text if any).
 * Real phoneme measurements always win over model estimates.
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
        private SpeechAiCoachService $aiCoach,
        private SpeechRetentionService $retention,
        private ProgressService $progress,
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

        // When instruments left gaps (no MFA aligner, open speech, missing
        // timings), ask the AI coach for scores + teachable notes. Measured
        // values always win; the model only fills nulls.
        $measurementForCoach = $components + [
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
            'word_rows' => $wordRows,
            'phoneme_issues' => $phonemeResult['issues'],
            'findings' => $recorded['findings'],
            'not_measured' => $notMeasured,
        ];

        // Persist transcript early so the coach prompt can read it off the model.
        $attempt->forceFill([
            'transcript' => $stt->transcript,
            'duration_ms' => $durationMs ?? $fluencyMetrics['speaking_ms'],
        ])->save();

        $coach = $this->aiCoach->coach($attempt, $measurementForCoach);
        $scoringSource = null;
        $fallbackOverall = null;

        if ($coach['ok'] && is_array($coach['scores'])) {
            [$components, $notMeasured] = $this->aiCoach->fillGaps(
                $components,
                $coach['scores'],
                $notMeasured,
            );
            $scoringSource = 'model';
            $fallbackOverall = $coach['scores']['overall'] ?? null;
        } elseif ($expectedTokens === [] && trim((string) ($stt->transcript ?? '')) !== '') {
            // Free speech + AI down: still fill scores so the UI is not empty.
            $heuristic = $this->aiCoach->heuristicFreeSpeechScores(
                (string) $stt->transcript,
                $durationMs,
                $fluencyMetrics['speech_rate_wpm'] ?? null,
                $fluencyMetrics['pause_count'] ?? null,
                $fluencyMetrics['filler_count'] ?? null,
            );
            [$components, $notMeasured] = $this->aiCoach->fillGaps(
                $components,
                $heuristic,
                $notMeasured,
            );
            $scoringSource = 'heuristic';
            $fallbackOverall = $heuristic['overall'] ?? null;
        } elseif ($components['pronunciation'] === null && $expectedTokens !== []) {
            // Aligner absent but we have a target: at least score the transcript match.
            $match = $this->aiCoach->transcriptMatchScore($wordRows, count($expectedTokens));
            if ($match !== null) {
                $components['pronunciation'] = $match;
                unset($notMeasured['pronunciation']);
                $scoringSource = 'transcript_match';
            }
        }

        $measurement = $components + [
            'overall_score' => $this->scorer->overall($components) ?? $fallbackOverall,
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

        // Prefer AI / heuristic overall when instruments still produced nothing.
        if ($measurement['overall_score'] === null && $fallbackOverall !== null) {
            $measurement['overall_score'] = $fallbackOverall;
        }

        $feedbackPayload = ($coach['ok'] && is_array($coach['feedback']))
            ? $this->mergeCoachFeedback($coach['feedback'], $measurement, $scoringSource)
            : $this->feedback->build($attempt, $measurement);

        if ($scoringSource && empty($feedbackPayload['scoring_source'])) {
            $feedbackPayload['scoring_source'] = $scoringSource;
        }

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
            'aligner' => $this->trim($alignerName ?? (in_array($scoringSource, ['model', 'heuristic'], true) ? ($scoringSource === 'model' ? 'ai_coach' : 'heuristic') : null), 48),
            'feedback' => $feedbackPayload,
            'scored_at' => now(),
        ])->save();

        // The profile is fed only from real phoneme measurements, so a run
        // without an aligner leaves the learner's history untouched rather than
        // polluting it with guesses.
        if ($phonemeResult['observations'] !== []) {
            $this->profile->record((int) $attempt->user_id, $phonemeResult['observations']);
        }

        $this->progress->recordSpeechScored($attempt->fresh());

        return $attempt->refresh();
    }

    /**
     * Refresh measured/not_measured on the AI coach payload after gap-fill.
     *
     * @param  array<string,mixed>  $feedback
     * @param  array<string,mixed>  $measurement
     * @return array<string,mixed>
     */
    private function mergeCoachFeedback(array $feedback, array $measurement, ?string $scoringSource): array
    {
        $feedback['measured'] = array_filter([
            'overall_score' => $measurement['overall_score'] ?? null,
            'pronunciation_score' => $measurement['pronunciation_score'] ?? null,
            'fluency_score' => $measurement['fluency_score'] ?? null,
            'grammar_score' => $measurement['grammar_score'] ?? null,
            'vocabulary_score' => $measurement['vocabulary_score'] ?? null,
            'completeness_score' => $measurement['completeness_score'] ?? null,
            'speech_rate_wpm' => $measurement['speech_rate_wpm'] ?? null,
            'pause_count' => $measurement['pause_count'] ?? null,
            'filler_count' => $measurement['filler_count'] ?? null,
        ], fn ($v) => $v !== null);
        $feedback['not_measured'] = $measurement['not_measured'] ?? [];
        if ($scoringSource) {
            $feedback['scoring_source'] = $scoringSource;
        }

        return $feedback;
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
