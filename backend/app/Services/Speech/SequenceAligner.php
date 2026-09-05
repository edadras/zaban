<?php

namespace App\Services\Speech;

/**
 * Needleman-Wunsch alignment over two token sequences.
 *
 * Both the word-level diff (expected sentence vs transcript) and the
 * phoneme-level diff (canonical pronunciation vs realised phones) are the same
 * problem, so they share one implementation. Edit distance alone would only give
 * a number; the backtrace is what tells us *which* word was dropped and which
 * one replaced it, and that is the part a learner can act on.
 */
class SequenceAligner
{
    public const MATCH = 'match';
    public const SUBSTITUTE = 'substitute';
    public const DELETE = 'delete';   // present in A, absent from B
    public const INSERT = 'insert';   // absent from A, present in B

    /**
     * @param  string[]  $a  reference sequence (expected)
     * @param  string[]  $b  hypothesis sequence (what was produced)
     * @return array<int,array{op:string,a:?string,b:?string,a_index:?int,b_index:?int}>
     */
    public function align(array $a, array $b): array
    {
        $a = array_values($a);
        $b = array_values($b);
        $n = count($a);
        $m = count($b);

        // Cost matrix. Substitution costs slightly less than a delete+insert pair
        // so a genuine replacement is reported as one substitution rather than
        // two unrelated edits.
        $d = [];
        for ($i = 0; $i <= $n; $i++) {
            $d[$i] = array_fill(0, $m + 1, 0.0);
            $d[$i][0] = $i;
        }
        for ($j = 0; $j <= $m; $j++) {
            $d[0][$j] = $j;
        }

        for ($i = 1; $i <= $n; $i++) {
            for ($j = 1; $j <= $m; $j++) {
                $sub = $d[$i - 1][$j - 1] + ($a[$i - 1] === $b[$j - 1] ? 0.0 : 0.9);
                $del = $d[$i - 1][$j] + 1.0;
                $ins = $d[$i][$j - 1] + 1.0;
                $d[$i][$j] = min($sub, $del, $ins);
            }
        }

        $ops = [];
        $i = $n;
        $j = $m;
        while ($i > 0 || $j > 0) {
            if ($i > 0 && $j > 0
                && abs($d[$i][$j] - ($d[$i - 1][$j - 1] + ($a[$i - 1] === $b[$j - 1] ? 0.0 : 0.9))) < 1e-9) {
                $ops[] = [
                    'op' => $a[$i - 1] === $b[$j - 1] ? self::MATCH : self::SUBSTITUTE,
                    'a' => $a[$i - 1],
                    'b' => $b[$j - 1],
                    'a_index' => $i - 1,
                    'b_index' => $j - 1,
                ];
                $i--;
                $j--;
            } elseif ($i > 0 && abs($d[$i][$j] - ($d[$i - 1][$j] + 1.0)) < 1e-9) {
                $ops[] = ['op' => self::DELETE, 'a' => $a[$i - 1], 'b' => null, 'a_index' => $i - 1, 'b_index' => null];
                $i--;
            } else {
                $ops[] = ['op' => self::INSERT, 'a' => null, 'b' => $b[$j - 1], 'a_index' => null, 'b_index' => $j - 1];
                $j--;
            }
        }

        return array_reverse($ops);
    }
}
