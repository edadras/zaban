<?php

namespace App\Services\Speech;

/**
 * Word-level diff between the expected text and what the learner actually said.
 *
 * The result is the backbone of the whole analysis: completeness, the concrete
 * grammar/vocabulary errors and the per-word rows all read off it.
 */
class WordAligner
{
    public const CORRECT = 'correct';
    public const MISPRONOUNCED = 'mispronounced';
    public const OMITTED = 'omitted';
    public const INSERTED = 'inserted';
    public const SUBSTITUTED = 'substituted';

    public function __construct(private SequenceAligner $aligner) {}

    /**
     * @param  array<int,array{raw:string,norm:string}>  $expected
     * @param  array<int,array{raw:string,norm:string,start_ms:?int,end_ms:?int,confidence:?float}>  $spoken
     * @return array<int,array{
     *     position:int, expected_word:?string, spoken_word:?string,
     *     expected_index:?int, spoken_index:?int,
     *     start_ms:?int, end_ms:?int, confidence:?float, outcome:string
     * }>
     */
    public function align(array $expected, array $spoken): array
    {
        // Free production: there is no reference, so every recognised word is
        // simply reported as spoken. Nothing here can be called an error.
        if ($expected === []) {
            $rows = [];
            foreach ($spoken as $i => $w) {
                $rows[] = [
                    'position' => $i,
                    'expected_word' => null,
                    'spoken_word' => $w['raw'],
                    'expected_index' => null,
                    'spoken_index' => $i,
                    'start_ms' => $w['start_ms'] ?? null,
                    'end_ms' => $w['end_ms'] ?? null,
                    'confidence' => $w['confidence'] ?? null,
                    'outcome' => self::CORRECT,
                ];
            }

            return $rows;
        }

        $ops = $this->aligner->align(
            array_column($expected, 'norm'),
            array_column($spoken, 'norm'),
        );

        $rows = [];
        $position = 0;
        foreach ($ops as $op) {
            $s = $op['b_index'] !== null ? ($spoken[$op['b_index']] ?? null) : null;
            $rows[] = [
                'position' => $position++,
                'expected_word' => $op['a_index'] !== null ? $expected[$op['a_index']]['raw'] : null,
                'spoken_word' => $s['raw'] ?? null,
                'expected_index' => $op['a_index'],
                'spoken_index' => $op['b_index'],
                'start_ms' => $s['start_ms'] ?? null,
                'end_ms' => $s['end_ms'] ?? null,
                'confidence' => $s['confidence'] ?? null,
                'outcome' => match ($op['op']) {
                    SequenceAligner::MATCH => self::CORRECT,
                    SequenceAligner::SUBSTITUTE => self::SUBSTITUTED,
                    SequenceAligner::DELETE => self::OMITTED,
                    default => self::INSERTED,
                },
            ];
        }

        return $rows;
    }
}
