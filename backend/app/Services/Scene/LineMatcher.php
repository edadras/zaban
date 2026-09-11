<?php

namespace App\Services\Scene;

/**
 * Did they say the line?
 *
 * Deliberately lexical. A learner reproducing a line in a scene is not writing
 * an essay, and sending every attempt to a model would put a network round trip
 * - and a bill - between pressing the microphone and the character answering.
 * What this has to be is fair: "I've got a headache" and "I have got a headache"
 * are the same line, "doctor" heard as "docter" is the same word, and a missing
 * full stop is nothing at all.
 *
 * It reports which words were missing and which were extra, because "72%" tells
 * a learner nothing and "you left out 'since'" tells them everything.
 */
class LineMatcher
{
    /** Below this a spoken or typed line is not the line. */
    public const PASS = 75;

    /** Close enough that the scene moves on but the debrief still notes it. */
    public const CLOSE = 60;

    private const CONTRACTIONS = [
        "i'm" => 'i am', "i've" => 'i have', "i'd" => 'i would', "i'll" => 'i will',
        "you're" => 'you are', "you've" => 'you have', "you'd" => 'you would', "you'll" => 'you will',
        "he's" => 'he is', "she's" => 'she is', "it's" => 'it is', "that's" => 'that is',
        "we're" => 'we are', "we've" => 'we have', "we'd" => 'we would', "we'll" => 'we will',
        "they're" => 'they are', "they've" => 'they have', "they'd" => 'they would', "they'll" => 'they will',
        "there's" => 'there is', "what's" => 'what is', "where's" => 'where is', "who's" => 'who is',
        "isn't" => 'is not', "aren't" => 'are not', "wasn't" => 'was not', "weren't" => 'were not',
        "don't" => 'do not', "doesn't" => 'does not', "didn't" => 'did not',
        "can't" => 'can not', 'cannot' => 'can not', "couldn't" => 'could not',
        "won't" => 'will not', "wouldn't" => 'would not', "shouldn't" => 'should not',
        "haven't" => 'have not', "hasn't" => 'has not', "hadn't" => 'had not',
        "let's" => 'let us',
    ];

    /**
     * Compare an attempt against every accepted wording and keep the best.
     *
     * @param  array<int,string>  $accepted
     * @return array{similarity:int,accepted:bool,close:bool,matched:?string,missing:array<int,string>,extra:array<int,string>}
     */
    public function match(string $given, array $accepted): array
    {
        $best = [
            'similarity' => 0,
            'accepted' => false,
            'close' => false,
            'matched' => null,
            'missing' => [],
            'extra' => [],
        ];

        $said = $this->words($given);

        foreach ($accepted as $candidate) {
            if (! is_string($candidate) || trim($candidate) === '') {
                continue;
            }
            $want = $this->words($candidate);
            $score = $this->similarity($want, $said);

            if ($score <= $best['similarity'] && $best['matched'] !== null) {
                continue;
            }

            $best = [
                'similarity' => $score,
                'accepted' => $score >= self::PASS,
                'close' => $score >= self::CLOSE && $score < self::PASS,
                'matched' => $candidate,
                'missing' => $this->difference($want, $said),
                'extra' => $this->difference($said, $want),
            ];
        }

        return $best;
    }

    /** Word-level edit distance, as a percentage of the longer line. */
    public function similarity(array $expected, array $actual): int
    {
        $a = count($expected);
        $b = count($actual);

        if ($a === 0 && $b === 0) {
            return 100;
        }
        if ($a === 0 || $b === 0) {
            return 0;
        }

        $previous = range(0, $b);

        for ($i = 1; $i <= $a; $i++) {
            $current = [$i];
            for ($j = 1; $j <= $b; $j++) {
                $current[$j] = min(
                    $previous[$j] + 1,
                    $current[$j - 1] + 1,
                    $previous[$j - 1] + ($this->sameWord($expected[$i - 1], $actual[$j - 1]) ? 0 : 1),
                );
            }
            $previous = $current;
        }

        return (int) max(0, round(100 * (1 - $previous[$b] / max($a, $b))));
    }

    /**
     * Break a line into comparable words.
     *
     * Contractions are expanded before punctuation is stripped, or "don't"
     * becomes "dont" and stops matching "do not".
     *
     * @return array<int,string>
     */
    public function words(string $line): array
    {
        $text = mb_strtolower(trim($line));
        $text = str_replace(['’', '‘', '`'], "'", $text);

        foreach (self::CONTRACTIONS as $short => $long) {
            $text = preg_replace('/\b'.preg_quote($short, '/').'\b/u', $long, $text);
        }

        $text = preg_replace('/[^\p{L}\p{N}\s\']/u', ' ', $text);
        $text = str_replace("'", '', $text);

        return array_values(array_filter(preg_split('/\s+/u', $text) ?: [], fn ($w) => $w !== ''));
    }

    /**
     * Two words are the same when they are spelled the same, or when one is a
     * near-miss of the other: speech recognition hears "docter", and refusing
     * the line over one letter teaches nothing about speaking.
     */
    private function sameWord(string $a, string $b): bool
    {
        if ($a === $b) {
            return true;
        }
        $length = max(mb_strlen($a), mb_strlen($b));

        return $length >= 5 && levenshtein($a, $b) <= 1;
    }

    /**
     * Words in the first list that the second does not answer for, counted
     * rather than set-subtracted so a line saying "very very" twice is not
     * satisfied by saying it once.
     *
     * @return array<int,string>
     */
    private function difference(array $from, array $against): array
    {
        $pool = $against;
        $out = [];

        foreach ($from as $word) {
            $found = null;
            foreach ($pool as $index => $candidate) {
                if ($this->sameWord($word, $candidate)) {
                    $found = $index;
                    break;
                }
            }
            if ($found === null) {
                $out[] = $word;
            } else {
                unset($pool[$found]);
            }
        }

        return array_slice($out, 0, 8);
    }
}
