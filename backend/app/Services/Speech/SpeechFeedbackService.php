<?php

namespace App\Services\Speech;

use App\Models\SpeechAttempt;

/**
 * Rules / secondary narrative when the AI coach is unavailable.
 *
 * Preferred path is [SpeechAiCoachService] (scores + coaching). This class
 * remains as a thin, observation-only fallback so a failed model call still
 * returns something teachable rather than an empty panel.
 */
class SpeechFeedbackService
{
    /**
     * @param  array<string,mixed>  $measurement
     * @return array<string,mixed>
     */
    public function build(SpeechAttempt $attempt, array $measurement): array
    {
        $observations = $this->observations($attempt, $measurement);
        $narrative = $this->fallbackNarrative($observations);

        return [
            'generated_at' => now()->toIso8601String(),
            'narrative_source' => $narrative['source'],
            'scoring_source' => null,
            'summary' => $narrative['strengths'][0] ?? null,
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

    /** @return array<string,mixed> */
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

    private function fallbackNarrative(array $observations): array
    {
        $strengths = [];
        $measured = $observations['measured'];
        $transcript = trim((string) ($observations['transcript'] ?? ''));

        if ($transcript !== '') {
            $strengths[] = 'We heard: "'.$this->shorten($transcript, 120).'"';
        }
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
            $strengths[] = 'You completed the recording — keep speaking; more speech gives the coach more to work with.';
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
        foreach (array_slice($observations['word_errors'], 0, 2) as $w) {
            if (($w['outcome'] ?? '') === WordAligner::OMITTED && $w['expected']) {
                $corrections[] = [
                    'issue' => "You skipped \"{$w['expected']}\".",
                    'why' => 'That word was in the target sentence.',
                    'fix' => "Practise the sentence again and include \"{$w['expected']}\".",
                ];
            } elseif (($w['outcome'] ?? '') === WordAligner::SUBSTITUTED && $w['expected']) {
                $corrections[] = [
                    'issue' => 'Heard "'.($w['spoken'] ?? '?')."\" where \"{$w['expected']}\" was expected.",
                    'why' => 'A different word reached the transcript.',
                    'fix' => "Slow down and aim for \"{$w['expected']}\".",
                ];
            }
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
                'activity' => 'Read the same sentence aloud twice more, slowly.',
                'reason' => 'The words that went wrong need another clean take.',
            ];
        }
        $wordCount = count(preg_split('/\s+/u', $transcript, -1, PREG_SPLIT_NO_EMPTY) ?: []);
        if ($practice === [] && $transcript !== '' && $wordCount < 8) {
            $practice[] = [
                'activity' => 'Say a little more next time — add one extra sentence about how you feel or what you did today.',
                'reason' => 'Your words were understood; a longer turn gives richer fluency and vocabulary feedback.',
            ];
        } elseif ($practice === [] && $transcript !== '') {
            $practice[] = [
                'activity' => 'Try the same idea again, but expand with one reason or example.',
                'reason' => 'Building on a clear turn is the fastest way to raise fluency and vocabulary scores.',
            ];
        }
        if ($practice === []) {
            $practice[] = [
                'activity' => 'Try again with a full sentence.',
                'reason' => 'Short clips leave little for coaching.',
            ];
        }

        return [
            'source' => 'rules',
            'strengths' => array_slice($strengths, 0, 3),
            'corrections' => array_slice($corrections, 0, 4),
            'phoneme_notes' => $notes,
            'practice' => array_slice($practice, 0, 3),
        ];
    }

    private function shorten(string $text, int $max): string
    {
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, $max - 1)).'…';
    }
}
