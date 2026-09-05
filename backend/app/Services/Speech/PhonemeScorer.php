<?php

namespace App\Services\Speech;

use App\Models\Phoneme;
use App\Models\SpeechAttempt;
use App\Models\SpeechWord;
use Illuminate\Support\Facades\DB;

/**
 * Turns forced-alignment output into per-phoneme rows and a pronunciation score.
 *
 * This class is only ever reached when a forced aligner actually ran. There is
 * deliberately no path from a bare transcript to a phoneme score: a transcript
 * says which words were recognised, never how the sounds inside them were
 * articulated, and inventing the difference would make the whole pronunciation
 * feature untrustworthy (spec 21).
 */
class PhonemeScorer
{
    /** Below this, an aligned phoneme counts as mispronounced rather than merely weak. */
    public const ACCURACY_THRESHOLD = 60.0;

    /** ARPABET and IPA both map to the same phoneme row, so either provider dialect resolves. */
    private array $inventoryCache = [];

    private array $ipaCache = [];

    public function __construct(private SequenceAligner $aligner) {}

    /**
     * @param  array<int,array{raw:string,norm:string}>  $expectedTokens
     * @param  array<int,SpeechWord>  $wordsByExpectedIndex
     * @param  array<int,array{word_index:int,phoneme:string,start_ms?:int,end_ms?:int,score?:float,expected?:string}>  $phonemes
     * @return array{
     *     scored:bool,
     *     pronunciation_score:?float,
     *     word_accuracy:array<int,float>,
     *     word_errors:array<int,int>,
     *     observations:array<int,array{phoneme_id:int,substituted_phoneme_id:?int,is_error:bool,word:?string}>,
     *     issues:array<int,array{ipa:string,expected:string,actual:?string,word:?string,accuracy:?float}>,
     *     unresolved_labels:array<int,string>
     * }
     */
    public function score(
        SpeechAttempt $attempt,
        array $expectedTokens,
        array $wordsByExpectedIndex,
        array $phonemes,
        int $languageId,
    ): array {
        $empty = [
            'scored' => false,
            'pronunciation_score' => null,
            'word_accuracy' => [],
            'word_errors' => [],
            'observations' => [],
            'issues' => [],
            'unresolved_labels' => [],
        ];

        if ($phonemes === []) {
            return $empty;
        }

        $lookup = $this->inventory($languageId);
        $canonicalByWord = $this->canonicalSequences($attempt, $expectedTokens);

        $grouped = [];
        foreach ($phonemes as $p) {
            $label = $this->normaliseLabel((string) ($p['phoneme'] ?? ''));
            if ($label === '') {
                continue;
            }
            $grouped[(int) ($p['word_index'] ?? 0)][] = $p + ['_label' => $label];
        }
        if ($grouped === []) {
            return $empty;
        }
        ksort($grouped);

        $rows = [];
        $observations = [];
        $issues = [];
        $unresolved = [];
        $wordAccuracy = [];
        $wordErrors = [];
        $accuracies = [];

        foreach ($grouped as $wordIndex => $entries) {
            $speechWord = $wordsByExpectedIndex[$wordIndex] ?? null;
            $wordRaw = $expectedTokens[$wordIndex]['raw'] ?? $speechWord?->expected_word;

            $realised = array_map(fn ($e) => $this->normaliseLabel((string) $e['_label']), $entries);
            $canonical = $canonicalByWord[$wordIndex]
                ?? array_map(fn ($e) => $this->normaliseLabel((string) ($e['expected'] ?? $e['_label'])), $entries);

            $ops = $this->aligner->align($canonical, $realised);

            $position = 0;
            $wordScores = [];
            $errorCount = 0;

            foreach ($ops as $op) {
                $entry = $op['b_index'] !== null ? ($entries[$op['b_index']] ?? null) : null;
                $providerScore = isset($entry['score']) ? (float) $entry['score'] : null;
                $expectedLabel = $op['a'];
                $actualLabel = $op['b'];

                [$accuracy, $isError] = match ($op['op']) {
                    SequenceAligner::MATCH => [
                        $providerScore === null ? null : round($providerScore * 100, 2),
                        $providerScore !== null && $providerScore * 100 < self::ACCURACY_THRESHOLD,
                    ],
                    default => [0.0, true],
                };

                $expectedId = $expectedLabel !== null ? ($lookup[$expectedLabel] ?? null) : null;
                $actualId = $actualLabel !== null ? ($lookup[$actualLabel] ?? null) : null;
                foreach ([$expectedLabel => $expectedId, $actualLabel => $actualId] as $label => $id) {
                    if ($label !== null && $label !== '' && $id === null) {
                        $unresolved[$label] = $label;
                    }
                }

                if ($speechWord) {
                    $rows[] = [
                        'speech_word_id' => $speechWord->id,
                        'expected_phoneme_id' => $expectedId,
                        'actual_phoneme_id' => $actualId,
                        'position' => $position,
                        'start_ms' => isset($entry['start_ms']) ? (int) $entry['start_ms'] : null,
                        'end_ms' => isset($entry['end_ms']) ? (int) $entry['end_ms'] : null,
                        'accuracy_score' => $accuracy,
                        'is_error' => $isError,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
                $position++;

                if ($accuracy !== null) {
                    $wordScores[] = $accuracy;
                    $accuracies[] = $accuracy;
                }
                if ($isError) {
                    $errorCount++;
                }

                // Only expected phonemes feed the profile: an inserted sound has
                // no "opportunity" to attach the statistic to.
                if ($expectedId !== null) {
                    $observations[] = [
                        'phoneme_id' => $expectedId,
                        'substituted_phoneme_id' => $op['op'] === SequenceAligner::SUBSTITUTE ? $actualId : null,
                        'is_error' => $isError,
                        'word' => $wordRaw,
                    ];
                }
                if ($isError && $expectedLabel !== null) {
                    $issues[] = [
                        'ipa' => $this->ipaFor($expectedId) ?? $expectedLabel,
                        'expected' => $expectedLabel,
                        'actual' => $actualLabel,
                        'word' => $wordRaw,
                        'accuracy' => $accuracy,
                    ];
                }
            }

            if ($speechWord) {
                $wordErrors[$speechWord->id] = $errorCount;
                if ($wordScores !== []) {
                    $wordAccuracy[$speechWord->id] = round(array_sum($wordScores) / count($wordScores), 2);
                }
            }
        }

        if ($rows !== []) {
            foreach (array_chunk($rows, 200) as $chunk) {
                DB::table('speech_phonemes')->insert($chunk);
            }
        }

        return [
            'scored' => $rows !== [],
            'pronunciation_score' => $accuracies === [] ? null : round(array_sum($accuracies) / count($accuracies), 2),
            'word_accuracy' => $wordAccuracy,
            'word_errors' => $wordErrors,
            'observations' => $observations,
            'issues' => $issues,
            'unresolved_labels' => array_values($unresolved),
        ];
    }

    /**
     * Canonical phoneme sequence per expected word, when the attempt targets a
     * pronunciation item whose dictionary sequence we hold. Without it the
     * aligner's own labels are the reference, which is correct for forced
     * alignment (the aligner works from the expected text's dictionary entry).
     *
     * @param  array<int,array{raw:string,norm:string}>  $expectedTokens
     * @return array<int,array<int,string>>
     */
    private function canonicalSequences(SpeechAttempt $attempt, array $expectedTokens): array
    {
        if (! $attempt->pronunciation_item_id || count($expectedTokens) !== 1) {
            return [];
        }

        $labels = DB::table('pronunciation_item_phonemes as pip')
            ->join('phonemes as p', 'p.id', '=', 'pip.phoneme_id')
            ->where('pip.pronunciation_item_id', $attempt->pronunciation_item_id)
            ->orderBy('pip.position')
            ->pluck('p.arpabet', 'pip.position')
            ->all();

        $labels = array_values(array_filter($labels));

        return $labels === [] ? [] : [0 => array_map(fn ($l) => $this->normaliseLabel((string) $l), $labels)];
    }

    /** @return array<string,int> */
    private function inventory(int $languageId): array
    {
        if (isset($this->inventoryCache[$languageId])) {
            return $this->inventoryCache[$languageId];
        }

        $map = [];
        foreach (Phoneme::where('language_id', $languageId)->get(['id', 'ipa', 'arpabet']) as $p) {
            $this->ipaCache[$p->id] = $p->ipa;
            $map[$this->normaliseLabel($p->ipa)] = $p->id;
            if ($p->arpabet) {
                // ARPABET is what aligners emit, so it wins any key collision with
                // an IPA symbol that happens to normalise to the same string.
                $map[$this->normaliseLabel($p->arpabet)] = $p->id;
            }
        }

        return $this->inventoryCache[$languageId] = $map;
    }

    private function ipaFor(?int $phonemeId): ?string
    {
        return $phonemeId === null ? null : ($this->ipaCache[$phonemeId] ?? null);
    }

    /** Strips ARPABET stress digits ("AA1" -> "AA") and case, leaving IPA untouched. */
    private function normaliseLabel(string $label): string
    {
        $label = trim($label);
        if ($label === '') {
            return '';
        }
        if (preg_match('/^[A-Za-z]{1,3}[0-2]?$/', $label)) {
            return strtoupper(rtrim($label, '012'));
        }

        return $label;
    }
}
