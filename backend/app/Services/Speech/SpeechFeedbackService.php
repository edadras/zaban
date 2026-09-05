<?php

namespace App\Services\Speech;

use App\AI\AiOrchestrator;
use App\AI\Support\TextRequest;
use App\Models\SpeechAttempt;

/**
 * Turns the measurements into something teachable.
 *
 * The division of labour is strict: measurement produces every number and every
 * observation, and the model is only allowed to phrase them. It is given the
 * findings and told to work from them, and if it is unavailable the rules-based
 * summary below is used instead - which is why a failed AI call degrades the
 * wording of the feedback and never its accuracy (spec 20).
 */
class SpeechFeedbackService
{
    private const SCHEMA = [
        'type' => 'object',
        'required' => ['strengths', 'corrections', 'phoneme_notes', 'practice'],
        'properties' => [
            'strengths' => [
                'type' => 'array',
                'maxItems' => 3,
                'items' => ['type' => 'string'],
            ],
            'corrections' => [
                'type' => 'array',
                'maxItems' => 3,
                'items' => [
                    'type' => 'object',
                    'required' => ['issue', 'why', 'fix'],
                    'properties' => [
                        'issue' => ['type' => 'string'],
                        'why' => ['type' => 'string'],
                        'fix' => ['type' => 'string'],
                    ],
                ],
            ],
            'phoneme_notes' => [
                'type' => 'array',
                'maxItems' => 4,
                'items' => [
                    'type' => 'object',
                    'required' => ['phoneme', 'words', 'tip'],
                    'properties' => [
                        'phoneme' => ['type' => 'string'],
                        'words' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'tip' => ['type' => 'string'],
                    ],
                ],
            ],
            'practice' => [
                'type' => 'array',
                'maxItems' => 3,
                'items' => [
                    'type' => 'object',
                    'required' => ['activity', 'reason'],
                    'properties' => [
                        'activity' => ['type' => 'string'],
                        'reason' => ['type' => 'string'],
                    ],
                ],
            ],
        ],
    ];

    private const SYSTEM = <<<'TXT'
    You are a speech coach for English learners. You are given measurements taken
    from a learner's recording. Write the human-readable parts of the feedback.

    Rules:
    - Use only the observations supplied. Do not invent errors, words or sounds.
    - Never state, estimate or re-word a numeric score; the app shows those itself.
    - If a measurement is marked unavailable, do not comment on it at all.
    - Be concrete: name the word or sound, then say what to do differently.
    - Encouraging, plain English, second person, no jargon beyond phoneme symbols.
    TXT;

    public function __construct(private AiOrchestrator $ai) {}

    /**
     * @param  array<string,mixed>  $measurement  output of SpeechAnalysisService
     * @return array<string,mixed> the feedback payload stored on the attempt
     */
    public function build(SpeechAttempt $attempt, array $measurement): array
    {
        $observations = $this->observations($attempt, $measurement);
        $narrative = $this->narrative($attempt, $observations) ?? $this->fallbackNarrative($observations);

        return [
            'generated_at' => now()->toIso8601String(),
            'narrative_source' => $narrative['source'],
            'strengths' => $narrative['strengths'],
            'corrections' => $narrative['corrections'],
            'phoneme_notes' => $narrative['phoneme_notes'],
            'practice' => $narrative['practice'],
            'measured' => $observations['measured'],
            'not_measured' => $observations['not_measured'],
            'observations' => [
                'word_errors' => $observations['word_errors'],
                'phoneme_issues' => $observations['phoneme_issues'],
                'language_errors' => $observations['language_errors'],
            ],
        ];
    }

    /**
     * Everything the narrative is allowed to draw on, in one structure. This is
     * also what gets stored, so the feedback is auditable after the fact.
     *
     * @return array<string,mixed>
     */
    private function observations(SpeechAttempt $attempt, array $measurement): array
    {
        $measured = array_filter([
            'overall_score' => $measurement['overall_score'] ?? null,
            'pronunciation_score' => $measurement['pronunciation_score'] ?? null,
            'fluency_score' => $measurement['fluency_score'] ?? null,
            'grammar_score' => $measurement['grammar_score'] ?? null,
            'vocabulary_score' => $measurement['vocabulary_score'] ?? null,
            'completeness_score' => $measurement['completeness_score'] ?? null,
            'speech_rate_wpm' => $measurement['speech_rate_wpm'] ?? null,
            'pause_count' => $measurement['pause_count'] ?? null,
            'total_pause_ms' => $measurement['total_pause_ms'] ?? null,
            'filler_count' => $measurement['filler_count'] ?? null,
        ], fn ($v) => $v !== null);

        $wordErrors = [];
        foreach ($measurement['word_rows'] ?? [] as $row) {
            if ($row['outcome'] === WordAligner::CORRECT) {
                continue;
            }
            $wordErrors[] = [
                'expected' => $row['expected_word'],
                'spoken' => $row['spoken_word'],
                'outcome' => $row['outcome'],
            ];
        }

        return [
            'measured' => $measured,
            'not_measured' => $measurement['not_measured'] ?? [],
            'word_errors' => array_slice($wordErrors, 0, 12),
            'phoneme_issues' => array_slice($this->groupPhonemeIssues($measurement['phoneme_issues'] ?? []), 0, 6),
            'language_errors' => array_slice(array_map(fn ($f) => [
                'type' => $f['error_type'],
                'subtype' => $f['error_subtype'],
                'expected' => $f['expected'],
                'said' => $f['input'],
                'message' => $f['message'],
            ], $measurement['findings'] ?? []), 0, 10),
            'expected_text' => $attempt->expected_text,
            'transcript' => $attempt->transcript,
        ];
    }

    /** One entry per problem sound, carrying the words it went wrong in. */
    private function groupPhonemeIssues(array $issues): array
    {
        $byPhoneme = [];
        foreach ($issues as $issue) {
            $key = $issue['ipa'];
            $byPhoneme[$key] ??= ['phoneme' => $key, 'count' => 0, 'words' => [], 'heard_as' => []];
            $byPhoneme[$key]['count']++;
            if (! empty($issue['word']) && ! in_array($issue['word'], $byPhoneme[$key]['words'], true)) {
                $byPhoneme[$key]['words'][] = $issue['word'];
            }
            if (! empty($issue['actual']) && ! in_array($issue['actual'], $byPhoneme[$key]['heard_as'], true)) {
                $byPhoneme[$key]['heard_as'][] = $issue['actual'];
            }
        }

        usort($byPhoneme, fn ($a, $b) => $b['count'] <=> $a['count']);

        return array_values($byPhoneme);
    }

    /** @return array<string,mixed>|null null when no model was available */
    private function narrative(SpeechAttempt $attempt, array $observations): ?array
    {
        $payload = json_encode([
            'target_text' => $observations['expected_text'],
            'transcript' => $observations['transcript'],
            'word_errors' => $observations['word_errors'],
            'phoneme_issues' => $observations['phoneme_issues'],
            'language_errors' => $observations['language_errors'],
            'unavailable_measurements' => $observations['not_measured'],
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        $result = $this->ai->text(new TextRequest(
            feature: 'speech.feedback',
            prompt: "Learner attempt observations:\n{$payload}",
            system: self::SYSTEM,
            schema: self::SCHEMA,
            temperature: 0.4,
            maxTokens: 900,
            userId: $attempt->user_id,
            // Feedback is about one learner's recording; reusing another
            // attempt's wording would be wrong even for identical text.
            cacheable: false,
        ));

        if (! $result->ok || ! is_array($result->json)) {
            return null;
        }

        return [
            'source' => 'model',
            'strengths' => array_values(array_filter((array) ($result->json['strengths'] ?? []), 'is_string')),
            'corrections' => $this->normaliseList($result->json['corrections'] ?? [], ['issue', 'why', 'fix']),
            'phoneme_notes' => $this->normaliseList($result->json['phoneme_notes'] ?? [], ['phoneme', 'words', 'tip']),
            'practice' => $this->normaliseList($result->json['practice'] ?? [], ['activity', 'reason']),
        ];
    }

    /**
     * Rules-based feedback used whenever the model is unavailable. It is thinner
     * than the generated version but it is built from the same observations, so
     * it is never wrong.
     */
    private function fallbackNarrative(array $observations): array
    {
        $strengths = [];
        $measured = $observations['measured'];

        if ($observations['word_errors'] === [] && $observations['expected_text']) {
            $strengths[] = 'Every word of the target sentence came through clearly.';
        }
        if (($measured['filler_count'] ?? 1) === 0) {
            $strengths[] = 'You spoke without hesitation sounds.';
        }
        if (isset($measured['speech_rate_wpm'])
            && $measured['speech_rate_wpm'] >= FluencyAnalyser::RATE_TARGET_MIN
            && $measured['speech_rate_wpm'] <= FluencyAnalyser::RATE_TARGET_MAX) {
            $strengths[] = 'Your speaking pace was in a natural conversational range.';
        }
        if ($strengths === []) {
            $strengths[] = 'You completed the recording - that is the part that builds speaking confidence.';
        }

        $corrections = [];
        foreach (array_slice($observations['language_errors'], 0, 3) as $e) {
            $corrections[] = [
                'issue' => $e['message'],
                'why' => 'Recorded as a '.str_replace('_', ' ', (string) $e['type']).' issue for later review.',
                'fix' => $e['expected']
                    ? "Say it again with \"{$e['expected']}\" in place."
                    : 'Say the sentence again, matching the target text exactly.',
            ];
        }

        $notes = [];
        foreach (array_slice($observations['phoneme_issues'], 0, 3) as $issue) {
            $heard = $issue['heard_as'] ? ' (heard as '.implode(', ', $issue['heard_as']).')' : '';
            $notes[] = [
                'phoneme' => $issue['phoneme'],
                'words' => $issue['words'],
                'tip' => "The sound {$issue['phoneme']}{$heard} needs work in: ".implode(', ', $issue['words']).'.',
            ];
        }

        $practice = [];
        if ($notes !== []) {
            $practice[] = [
                'activity' => 'Minimal-pair drill on '.implode(' and ', array_column($notes, 'phoneme')).'.',
                'reason' => 'These sounds were measurably off in this recording.',
            ];
        }
        if ($corrections !== []) {
            $practice[] = [
                'activity' => 'Read the target sentence aloud twice more, slowly.',
                'reason' => 'The words that went wrong were structural, not just fast speech.',
            ];
        }
        if ($practice === []) {
            $practice[] = [
                'activity' => 'Record a longer answer on the same topic.',
                'reason' => 'Nothing measurable went wrong here; more speech gives more to work with.',
            ];
        }

        return [
            'source' => 'rules',
            'strengths' => $strengths,
            'corrections' => $corrections,
            'phoneme_notes' => $notes,
            'practice' => $practice,
        ];
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
}
