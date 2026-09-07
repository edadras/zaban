<?php

namespace App\Services\Speech;

use App\AI\AiOrchestrator;
use App\AI\Support\TextRequest;
use App\Models\SpeechAttempt;
use Illuminate\Support\Facades\Log;

/**
 * AI speech coach: fills scores and teachable notes when hardware alignment
 * is missing or the learner is speaking freely (no target sentence).
 *
 * Measured phoneme/fluency numbers from the pipeline always win when present.
 * The model only fills gaps and writes coaching prose.
 */
class SpeechAiCoachService
{
    /**
     * OpenAI strict json_schema: every object needs additionalProperties:false
     * and every property listed in required.
     */
    private const SCHEMA = [
        'type' => 'object',
        'additionalProperties' => false,
        'required' => [
            'scores',
            'strengths',
            'strengths_fa',
            'corrections',
            'phoneme_notes',
            'practice',
            'summary',
            'summary_fa',
        ],
        'properties' => [
            'scores' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => [
                    'overall',
                    'pronunciation',
                    'fluency',
                    'grammar',
                    'vocabulary',
                    'completeness',
                ],
                'properties' => [
                    'overall' => ['type' => 'number'],
                    'pronunciation' => ['type' => 'number'],
                    'fluency' => ['type' => 'number'],
                    'grammar' => ['type' => 'number'],
                    'vocabulary' => ['type' => 'number'],
                    'completeness' => ['type' => 'number'],
                ],
            ],
            'summary' => ['type' => 'string'],
            'summary_fa' => ['type' => 'string'],
            'strengths' => [
                'type' => 'array',
                'maxItems' => 3,
                'items' => ['type' => 'string'],
            ],
            'strengths_fa' => [
                'type' => 'array',
                'maxItems' => 3,
                'items' => ['type' => 'string'],
            ],
            'corrections' => [
                'type' => 'array',
                'maxItems' => 4,
                'items' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['issue', 'issue_fa', 'why', 'why_fa', 'fix', 'fix_fa'],
                    'properties' => [
                        'issue' => ['type' => 'string'],
                        'issue_fa' => ['type' => 'string'],
                        'why' => ['type' => 'string'],
                        'why_fa' => ['type' => 'string'],
                        'fix' => ['type' => 'string'],
                        'fix_fa' => ['type' => 'string'],
                    ],
                ],
            ],
            'phoneme_notes' => [
                'type' => 'array',
                'maxItems' => 4,
                'items' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['phoneme', 'words', 'tip', 'tip_fa'],
                    'properties' => [
                        'phoneme' => ['type' => 'string'],
                        'words' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                        ],
                        'tip' => ['type' => 'string'],
                        'tip_fa' => ['type' => 'string'],
                    ],
                ],
            ],
            'practice' => [
                'type' => 'array',
                'maxItems' => 3,
                'items' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['activity', 'activity_fa', 'reason', 'reason_fa'],
                    'properties' => [
                        'activity' => ['type' => 'string'],
                        'activity_fa' => ['type' => 'string'],
                        'reason' => ['type' => 'string'],
                        'reason_fa' => ['type' => 'string'],
                    ],
                ],
            ],
        ],
    ];

    private const SYSTEM = <<<'TXT'
    You are a speech coach for English learners. You receive the transcript of
    what the learner said (from speech recognition), optional target text, word
    mismatches, and any instrument measurements that already exist.

    Score each dimension 0-100:
    - pronunciation: how clearly and accurately they seem to have spoken
      (use target mismatches and transcript quality; this is an estimate when
      phoneme alignment is unavailable — still give a useful number)
    - fluency: pace, hesitation, natural flow (use WPM/pauses when given;
      otherwise judge from the transcript)
    - grammar: accuracy of the language they produced
    - vocabulary: range and appropriateness of word choice (for free speech);
      if they were reading a target sentence, still score how well they handled
      the words, not invent a fake lexical range
    - completeness: if there is a target text, how much of it they covered;
      for free speech, how complete and communicative the answer feels
    - overall: balanced summary of the above

    Coaching rules:
    - Be concrete: quote words from the transcript or target.
    - Encouraging, plain English, second person for English fields.
    - strengths: up to 3 real positives (English).
    - strengths_fa: Persian translation of each strength, same order/count.
    - corrections: up to 4 actionable fixes (issue / why / fix) in English,
      plus issue_fa / why_fa / fix_fa Persian translations.
    - phoneme_notes: tip + tip_fa; only when you can name a likely sound issue.
    - practice: activity/reason in English and activity_fa/reason_fa in Persian.
    - summary + summary_fa: one or two sentences the learner can read first.
    - Do not invent that they said words that are not in the transcript.
    - Persian must be natural and accurate, not word-for-word calque.
    TXT;

    public function __construct(private AiOrchestrator $ai) {}

    /**
     * @param  array<string,mixed>  $measurement
     * @return array{
     *     ok:bool,
     *     scores:?array<string,?float>,
     *     feedback:?array<string,mixed>,
     *     error:?string
     * }
     */
    public function coach(SpeechAttempt $attempt, array $measurement): array
    {
        $transcript = trim((string) ($attempt->transcript ?? ''));
        if ($transcript === '') {
            return ['ok' => false, 'scores' => null, 'feedback' => null, 'error' => 'empty_transcript'];
        }

        $payload = [
            'target_text' => $attempt->expected_text,
            'transcript' => $transcript,
            'already_measured' => array_filter([
                'pronunciation' => $measurement['pronunciation_score'] ?? null,
                'fluency' => $measurement['fluency_score'] ?? null,
                'grammar' => $measurement['grammar_score'] ?? null,
                'vocabulary' => $measurement['vocabulary_score'] ?? null,
                'completeness' => $measurement['completeness_score'] ?? null,
                'overall' => $measurement['overall_score'] ?? null,
                'speech_rate_wpm' => $measurement['speech_rate_wpm'] ?? null,
                'pause_count' => $measurement['pause_count'] ?? null,
                'filler_count' => $measurement['filler_count'] ?? null,
            ], fn ($v) => $v !== null),
            'unavailable' => $measurement['not_measured'] ?? [],
            'word_errors' => $this->wordErrors($measurement),
            'language_errors' => array_slice(array_map(fn ($f) => [
                'type' => $f['error_type'] ?? null,
                'message' => $f['message'] ?? null,
                'expected' => $f['expected'] ?? null,
                'said' => $f['input'] ?? null,
            ], $measurement['findings'] ?? []), 0, 10),
        ];

        $result = $this->ai->text(new TextRequest(
            feature: 'speech.feedback',
            prompt: "Learner speech attempt:\n".json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            system: self::SYSTEM,
            schema: self::SCHEMA,
            temperature: 0.35,
            maxTokens: 1200,
            userId: $attempt->user_id,
            cacheable: false,
            metadata: ['speech_attempt_id' => $attempt->id],
        ));

        if (! $result->ok || ! is_array($result->json)) {
            Log::warning('speech.ai_coach.failed', [
                'speech_attempt_id' => $attempt->id,
                'error' => $result->error,
            ]);

            return ['ok' => false, 'scores' => null, 'feedback' => null, 'error' => $result->error];
        }

        $json = $result->json;
        $summary = is_string($json['summary'] ?? null) ? trim((string) $json['summary']) : null;
        $summaryFa = is_string($json['summary_fa'] ?? null) ? trim((string) $json['summary_fa']) : null;
        $strengths = array_values(array_filter((array) ($json['strengths'] ?? []), 'is_string'));
        $strengthsFa = array_values(array_filter((array) ($json['strengths_fa'] ?? []), 'is_string'));
        if ($summary) {
            array_unshift($strengths, $summary);
            $strengths = array_values(array_unique($strengths));
            if ($summaryFa) {
                array_unshift($strengthsFa, $summaryFa);
                $strengthsFa = array_values(array_unique($strengthsFa));
            }
        }

        return [
            'ok' => true,
            'scores' => $this->normaliseScores($json['scores'] ?? []),
            'feedback' => [
                'generated_at' => now()->toIso8601String(),
                'narrative_source' => 'model',
                'scoring_source' => 'model',
                'summary' => $summary,
                'summary_fa' => $summaryFa,
                'strengths' => $strengths,
                'strengths_fa' => $strengthsFa,
                'corrections' => $this->normaliseList($json['corrections'] ?? [], [
                    'issue', 'issue_fa', 'why', 'why_fa', 'fix', 'fix_fa',
                ]),
                'phoneme_notes' => $this->normalisePhonemeNotes($json['phoneme_notes'] ?? []),
                'practice' => $this->normaliseList($json['practice'] ?? [], [
                    'activity', 'activity_fa', 'reason', 'reason_fa',
                ]),
                'measured' => array_filter([
                    'overall_score' => $measurement['overall_score'] ?? null,
                    'pronunciation_score' => $measurement['pronunciation_score'] ?? null,
                    'fluency_score' => $measurement['fluency_score'] ?? null,
                    'grammar_score' => $measurement['grammar_score'] ?? null,
                    'vocabulary_score' => $measurement['vocabulary_score'] ?? null,
                    'completeness_score' => $measurement['completeness_score'] ?? null,
                ], fn ($v) => $v !== null),
                'not_measured' => $measurement['not_measured'] ?? [],
                'observations' => [
                    'word_errors' => $payload['word_errors'],
                    'phoneme_issues' => [],
                    'language_errors' => $payload['language_errors'],
                ],
            ],
            'error' => null,
        ];
    }

    /**
     * Fill only the score slots that the instruments left empty.
     *
     * @param  array<string,?float>  $components
     * @param  array<string,?float>  $aiScores
     * @param  array<string,string>  $notMeasured
     * @return array{0: array<string,?float>, 1: array<string,string>}
     */
    public function fillGaps(array $components, array $aiScores, array $notMeasured, array $protected = []): array
    {
        foreach (['pronunciation', 'fluency', 'grammar', 'vocabulary', 'completeness'] as $key) {
            if (($components[$key] ?? null) !== null) {
                continue;
            }
            /*
             * Some gaps are instrumental - no aligner, the model unreachable -
             * and an estimate is better than a blank. Others are structural:
             * completeness and grammar are both deviation from a target text,
             * and open speech has none, so there is nothing to be right or
             * wrong about. Filling those would not be estimating a number, it
             * would be inventing the question it answers.
             */
            if (in_array($key, $protected, true)) {
                continue;
            }
            if (! isset($aiScores[$key]) || $aiScores[$key] === null) {
                continue;
            }
            $components[$key] = $aiScores[$key];
            unset($notMeasured[$key]);
        }

        return [$components, $notMeasured];
    }

    /**
     * Offline scores for free speech when the AI coach is unreachable.
     * Keeps the result screen useful (ring + dimensions) instead of blanks.
     *
     * @return array<string,float>
     */
    public function heuristicFreeSpeechScores(
        string $transcript,
        ?int $durationMs = null,
        ?float $speechRateWpm = null,
        ?int $pauseCount = null,
        ?int $fillerCount = null,
    ): array {
        $words = preg_split('/\s+/u', trim($transcript), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $n = count($words);
        $chars = mb_strlen(preg_replace('/\s+/u', '', $transcript) ?? '');

        // Length / communicativeness: short greetings still get a fair mid score.
        $completeness = match (true) {
            $n >= 40 => 92.0,
            $n >= 20 => 85.0,
            $n >= 12 => 78.0,
            $n >= 6 => 72.0,
            $n >= 3 => 65.0,
            $n >= 1 => 55.0,
            default => 40.0,
        };

        $unique = count(array_unique(array_map(static fn ($w) => mb_strtolower($w), $words)));
        $vocabRatio = $n > 0 ? ($unique / $n) : 0.0;
        $vocabulary = round(min(92.0, 48.0 + ($n * 1.6) + ($vocabRatio * 22.0)), 2);

        // Light grammar proxy: punctuation / capitals are weak signals; reward
        // multi-word utterances and lightly penalise very short fragments.
        $grammar = match (true) {
            $n >= 8 => 82.0,
            $n >= 4 => 78.0,
            $n >= 2 => 70.0,
            default => 60.0,
        };
        if (preg_match('/\b(i|im|i\'m)\b/i', $transcript) && ! preg_match('/\bI\b/', $transcript)) {
            $grammar = max(55.0, $grammar - 8.0);
        }

        $fluency = 70.0;
        if ($speechRateWpm !== null) {
            if ($speechRateWpm >= FluencyAnalyser::RATE_TARGET_MIN
                && $speechRateWpm <= FluencyAnalyser::RATE_TARGET_MAX) {
                $fluency = 85.0;
            } elseif ($speechRateWpm < 60) {
                $fluency = 58.0;
            } elseif ($speechRateWpm > 200) {
                $fluency = 62.0;
            } else {
                $fluency = 74.0;
            }
        } elseif ($durationMs !== null && $durationMs > 0 && $n > 0) {
            $wpm = ($n / max(0.4, $durationMs / 60000.0));
            $fluency = $wpm < 50 ? 58.0 : ($wpm > 190 ? 62.0 : 74.0);
        }
        if (($fillerCount ?? 0) >= 3) {
            $fluency = max(45.0, $fluency - 12.0);
        }
        if (($pauseCount ?? 0) >= 5) {
            $fluency = max(45.0, $fluency - 8.0);
        }

        // Clarity estimate from transcript density (Whisper heard something coherent).
        $pronunciation = match (true) {
            $n >= 6 && $chars >= 20 => 80.0,
            $n >= 3 => 74.0,
            $n >= 1 => 68.0,
            default => 50.0,
        };

        $overall = round(
            ($pronunciation * 0.25)
            + ($fluency * 0.2)
            + ($grammar * 0.2)
            + ($vocabulary * 0.15)
            + ($completeness * 0.2),
            2
        );

        return [
            'overall' => $overall,
            'pronunciation' => $pronunciation,
            'fluency' => round($fluency, 2),
            'grammar' => $grammar,
            'vocabulary' => $vocabulary,
            'completeness' => $completeness,
        ];
    }

    /**
     * Cheap transcript-match score when a target sentence exists and alignment
     * did not run — better than leaving the ring blank if the AI call fails.
     *
     * @param  array<int,array{outcome:string}>  $wordRows
     */
    public function transcriptMatchScore(array $wordRows, int $expectedCount): ?float
    {
        if ($expectedCount <= 0 || $wordRows === []) {
            return null;
        }

        $scored = 0;
        $hits = 0.0;
        foreach ($wordRows as $row) {
            if (($row['expected_word'] ?? null) === null && ($row['outcome'] ?? null) === WordAligner::INSERTED) {
                continue;
            }
            if (($row['expected_word'] ?? null) === null) {
                continue;
            }
            $scored++;
            $hits += match ($row['outcome'] ?? '') {
                WordAligner::CORRECT => 1.0,
                WordAligner::SUBSTITUTED, WordAligner::MISPRONOUNCED => 0.45,
                WordAligner::OMITTED => 0.0,
                default => 0.2,
            };
        }

        if ($scored === 0) {
            return null;
        }

        return round(max(0.0, min(100.0, ($hits / $scored) * 100)), 2);
    }

    /** @return list<array{expected:?string,spoken:?string,outcome:string}> */
    private function wordErrors(array $measurement): array
    {
        $out = [];
        foreach ($measurement['word_rows'] ?? [] as $row) {
            if (($row['outcome'] ?? null) === WordAligner::CORRECT) {
                continue;
            }
            $out[] = [
                'expected' => $row['expected_word'] ?? null,
                'spoken' => $row['spoken_word'] ?? null,
                'outcome' => $row['outcome'] ?? '',
            ];
        }

        return array_slice($out, 0, 12);
    }

    /** @return array<string,?float> */
    private function normaliseScores(mixed $raw): array
    {
        $out = [];
        foreach (['overall', 'pronunciation', 'fluency', 'grammar', 'vocabulary', 'completeness'] as $key) {
            $value = is_array($raw) ? ($raw[$key] ?? null) : null;
            $out[$key] = is_numeric($value) ? round(max(0, min(100, (float) $value)), 2) : null;
        }

        return $out;
    }

    /** @param string[] $keys */
    private function normaliseList(mixed $rows, array $keys): array
    {
        if (! is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $entry = [];
            foreach ($keys as $key) {
                $entry[$key] = $row[$key] ?? null;
            }
            $out[] = $entry;
        }

        return $out;
    }

    private function normalisePhonemeNotes(mixed $rows): array
    {
        if (! is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $words = $row['words'] ?? [];
            if (! is_array($words)) {
                $words = [];
            }
            $out[] = [
                'phoneme' => $row['phoneme'] ?? null,
                'words' => array_values(array_filter($words, 'is_string')),
                'tip' => $row['tip'] ?? null,
                'tip_fa' => $row['tip_fa'] ?? null,
            ];
        }

        return $out;
    }
}
