<?php

namespace App\Services\Speech;

use App\Models\LearnerError;
use App\Services\Learning\RemediationService;

/**
 * Reads concrete, nameable mistakes out of the word-level diff.
 *
 * "You scored 72" teaches nothing. "You dropped the article before 'bus'" does,
 * and it is also the form the remediation engine can act on, so every finding
 * here is classified into the same error_type vocabulary the rest of the
 * learning engine uses (spec 22).
 */
class TranscriptErrorDetector
{
    private const ARTICLES = ['a', 'an', 'the'];

    private const PREPOSITIONS = [
        'in', 'on', 'at', 'to', 'for', 'of', 'from', 'with', 'by', 'about',
        'into', 'onto', 'over', 'under', 'between', 'through', 'during', 'after', 'before',
    ];

    private const AUXILIARIES = ['is', 'are', 'was', 'were', 'am', 'be', 'been', 'being', 'do', 'does', 'did', 'have', 'has', 'had'];

    /** Irregular pairs a suffix test cannot connect. */
    private const IRREGULAR_FORMS = [
        'go' => ['went', 'gone', 'goes'],
        'be' => ['was', 'were', 'is', 'are', 'am', 'been'],
        'is' => ['are', 'was', 'were', 'am'],
        'are' => ['is', 'was', 'were', 'am'],
        'was' => ['were', 'is', 'are'],
        'were' => ['was', 'is', 'are'],
        'have' => ['has', 'had'],
        'has' => ['have', 'had'],
        'do' => ['does', 'did', 'done'],
        'take' => ['took', 'taken'],
        'make' => ['made'],
        'see' => ['saw', 'seen'],
        'eat' => ['ate', 'eaten'],
        'give' => ['gave', 'given'],
        'come' => ['came'],
        'child' => ['children'],
        'man' => ['men'],
        'woman' => ['women'],
        'person' => ['people'],
        'foot' => ['feet'],
        'tooth' => ['teeth'],
        'mouse' => ['mice'],
        'good' => ['better', 'best'],
        'bad' => ['worse', 'worst'],
    ];

    /** How far apart a deletion and its matching insertion may sit and still be one transposition. */
    private const REORDER_WINDOW = 4;

    public function __construct(
        private TextTokeniser $tokeniser,
        private RemediationService $remediation,
    ) {}

    /**
     * Classify the diff without writing anything, so the caller can show the
     * findings even when it chooses not to persist them.
     *
     * @param  array<int,array{position:int,expected_word:?string,spoken_word:?string,outcome:string}>  $wordRows
     * @return array<int,array{
     *     error_type:string, error_subtype:string, expected:?string, input:?string,
     *     position:int, severity:int, message:string
     * }>
     */
    public function detect(array $wordRows): array
    {
        $findings = [];
        $consumed = [];

        foreach ($wordRows as $i => $row) {
            if (isset($consumed[$i])) {
                continue;
            }

            $expected = $row['expected_word'] !== null ? $this->tokeniser->normalise($row['expected_word']) : null;
            $spoken = $row['spoken_word'] !== null ? $this->tokeniser->normalise($row['spoken_word']) : null;

            if ($row['outcome'] === WordAligner::OMITTED && $expected !== null) {
                // A word that reappears a few slots later was moved, not dropped.
                $partner = $this->findReorderPartner($wordRows, $i, $expected, WordAligner::INSERTED);
                if ($partner !== null) {
                    $consumed[$partner] = true;
                    $findings[] = $this->finding('word_order', 'moved_word', $row['expected_word'], $row['expected_word'], $row['position'], 3,
                        "\"{$row['expected_word']}\" was said in the wrong place in the sentence.");

                    continue;
                }
                $findings[] = $this->omissionFinding($expected, $row);

                continue;
            }

            if ($row['outcome'] === WordAligner::INSERTED && $spoken !== null) {
                if ($this->tokeniser->isFiller($spoken)) {
                    continue; // Hesitation sounds are a fluency signal, not a language error.
                }
                $partner = $this->findReorderPartner($wordRows, $i, $spoken, WordAligner::OMITTED);
                if ($partner !== null) {
                    $consumed[$partner] = true;
                    $findings[] = $this->finding('word_order', 'moved_word', $row['spoken_word'], $row['spoken_word'], $row['position'], 3,
                        "\"{$row['spoken_word']}\" was said in the wrong place in the sentence.");

                    continue;
                }
                $findings[] = $this->insertionFinding($spoken, $row);

                continue;
            }

            if ($row['outcome'] === WordAligner::SUBSTITUTED && $expected !== null && $spoken !== null) {
                $findings[] = $this->substitutionFinding($expected, $spoken, $row);
            }
        }

        return $findings;
    }

    /**
     * Detect and persist. Returns the findings alongside the stored rows so the
     * feedback layer can talk about exactly what was recorded.
     *
     * @param  array<int,array{position:int,expected_word:?string,spoken_word:?string,outcome:string}>  $wordRows
     * @return array{findings:array<int,array<string,mixed>>, errors:array<int,LearnerError>}
     */
    public function record(int $userId, array $wordRows): array
    {
        $findings = $this->detect($wordRows);
        $errors = [];

        foreach ($findings as $f) {
            $errors[] = $this->remediation->recordError(
                userId: $userId,
                errorType: $f['error_type'],
                conceptId: null,
                skillId: null,
                input: $f['input'],
                expected: $f['expected'],
                subtype: $f['error_subtype'],
                severity: $f['severity'],
                confidence: 0.9,
                note: $f['message'],
            );
        }

        return ['findings' => $findings, 'errors' => $errors];
    }

    private function omissionFinding(string $expected, array $row): array
    {
        $word = $row['expected_word'];

        return match (true) {
            in_array($expected, self::ARTICLES, true) => $this->finding(
                'article', 'omitted_article', $word, null, $row['position'], 2,
                "The article \"{$word}\" was missing.",
            ),
            in_array($expected, self::PREPOSITIONS, true) => $this->finding(
                'preposition', 'omitted_preposition', $word, null, $row['position'], 2,
                "The preposition \"{$word}\" was missing.",
            ),
            in_array($expected, self::AUXILIARIES, true) => $this->finding(
                'grammar', 'omitted_auxiliary', $word, null, $row['position'], 3,
                "The auxiliary verb \"{$word}\" was missing.",
            ),
            default => $this->finding(
                'pronunciation', 'omitted_word', $word, null, $row['position'], 3,
                "\"{$word}\" was not said, or was not clear enough to be recognised.",
            ),
        };
    }

    private function insertionFinding(string $spoken, array $row): array
    {
        $word = $row['spoken_word'];

        return match (true) {
            in_array($spoken, self::ARTICLES, true) => $this->finding(
                'article', 'inserted_article', null, $word, $row['position'], 2,
                "An extra article \"{$word}\" was added.",
            ),
            in_array($spoken, self::PREPOSITIONS, true) => $this->finding(
                'preposition', 'inserted_preposition', null, $word, $row['position'], 2,
                "An extra preposition \"{$word}\" was added.",
            ),
            default => $this->finding(
                'grammar', 'inserted_word', null, $word, $row['position'], 2,
                "An extra word \"{$word}\" was added.",
            ),
        };
    }

    private function substitutionFinding(string $expected, string $spoken, array $row): array
    {
        $e = $row['expected_word'];
        $s = $row['spoken_word'];

        if ($this->sameLemma($expected, $spoken)) {
            return $this->finding('grammar', 'word_form', $e, $s, $row['position'], 3,
                "\"{$s}\" is the wrong form of \"{$e}\".");
        }
        if (in_array($expected, self::ARTICLES, true) && in_array($spoken, self::ARTICLES, true)) {
            return $this->finding('article', 'wrong_article', $e, $s, $row['position'], 2,
                "\"{$s}\" was used where \"{$e}\" is required.");
        }
        if (in_array($expected, self::PREPOSITIONS, true) && in_array($spoken, self::PREPOSITIONS, true)) {
            return $this->finding('preposition', 'wrong_preposition', $e, $s, $row['position'], 3,
                "\"{$s}\" was used where \"{$e}\" is required.");
        }
        // Near-homophones point at articulation rather than word choice.
        if ($this->nearHomophone($expected, $spoken)) {
            return $this->finding('pronunciation', 'near_homophone', $e, $s, $row['position'], 3,
                "\"{$e}\" came out sounding like \"{$s}\".");
        }

        return $this->finding('vocabulary_confusion', 'wrong_word', $e, $s, $row['position'], 3,
            "\"{$s}\" was said instead of \"{$e}\".");
    }

    /** Two surface forms of one lexeme: irregular pairs, or a shared stem plus an inflectional suffix. */
    private function sameLemma(string $a, string $b): bool
    {
        foreach ([[$a, $b], [$b, $a]] as [$x, $y]) {
            if (in_array($y, self::IRREGULAR_FORMS[$x] ?? [], true)) {
                return true;
            }
        }

        $short = strlen($a) <= strlen($b) ? $a : $b;
        $long = $short === $a ? $b : $a;
        if (strlen($short) < 3 || ! str_starts_with($long, substr($short, 0, max(3, strlen($short) - 1)))) {
            return false;
        }

        $suffix = substr($long, strlen($short));

        return in_array($suffix, ['s', 'es', 'ed', 'd', 'ing', 'er', 'est', 'ies', 'en'], true)
            || in_array(substr($long, strlen($short) - 1), ['ies', 'ied'], true);
    }

    /** One or two edits apart and similar length: much more likely a sound than a word choice. */
    private function nearHomophone(string $a, string $b): bool
    {
        return abs(strlen($a) - strlen($b)) <= 1
            && strlen($a) >= 3
            && levenshtein($a, $b) <= 1;
    }

    /**
     * @param  array<int,array{expected_word:?string,spoken_word:?string,outcome:string}>  $rows
     */
    private function findReorderPartner(array $rows, int $from, string $word, string $outcome): ?int
    {
        $keys = array_keys($rows);
        $pos = array_search($from, $keys, true);
        if ($pos === false) {
            return null;
        }

        for ($step = 1; $step <= self::REORDER_WINDOW; $step++) {
            foreach ([$pos + $step, $pos - $step] as $candidate) {
                if (! isset($keys[$candidate])) {
                    continue;
                }
                $row = $rows[$keys[$candidate]];
                if ($row['outcome'] !== $outcome) {
                    continue;
                }
                $other = $outcome === WordAligner::OMITTED ? $row['expected_word'] : $row['spoken_word'];
                if ($other !== null && $this->tokeniser->normalise($other) === $word) {
                    return $keys[$candidate];
                }
            }
        }

        return null;
    }

    private function finding(
        string $type,
        string $subtype,
        ?string $expected,
        ?string $input,
        int $position,
        int $severity,
        string $message,
    ): array {
        return [
            'error_type' => $type,
            'error_subtype' => $subtype,
            'expected' => $expected,
            'input' => $input,
            'position' => $position,
            'severity' => $severity,
            'message' => $message,
        ];
    }
}
