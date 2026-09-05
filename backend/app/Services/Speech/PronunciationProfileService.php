<?php

namespace App\Services\Speech;

use App\Models\MinimalPair;
use App\Models\Phoneme;
use App\Models\PronunciationError;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The learner's rolling per-phoneme pronunciation profile.
 *
 * This is the part of the speech feature that survives the recording. It is
 * anonymisable statistics - counts and rates against a phoneme, plus a handful
 * of example words - so deleting the audio (spec 45) costs the learner nothing
 * in teaching value.
 *
 * Two rates are kept on purpose. The lifetime error_rate answers "is this a
 * persistent weakness"; the windowed recent_error_rate answers "are they getting
 * better", and without the second one a learner who has fixed /θ/ would be
 * drilled on it forever.
 */
class PronunciationProfileService
{
    /** Weight given to the newest attempt in the recent-error-rate window. */
    public const RECENT_WEIGHT = 0.35;

    /** A rate needs this many opportunities behind it before it is worth acting on. */
    public const MIN_OPPORTUNITIES = 8;

    /** Recent error rate at or above this marks a phoneme as a live problem. */
    public const PROBLEM_THRESHOLD = 0.20;

    /** ...and at or below this it is treated as resolved. */
    public const RESOLVED_THRESHOLD = 0.08;

    private const MAX_EXAMPLE_WORDS = 8;

    /**
     * Fold one attempt's phoneme observations into the profile.
     *
     * @param  array<int,array{phoneme_id:int,substituted_phoneme_id:?int,is_error:bool,word:?string}>  $observations
     * @return array<int,PronunciationError> canonical rows touched, keyed by phoneme id
     */
    public function record(int $userId, array $observations): array
    {
        if ($observations === []) {
            return [];
        }

        $byPhoneme = [];
        foreach ($observations as $o) {
            $pid = (int) $o['phoneme_id'];
            $byPhoneme[$pid] ??= ['opportunities' => 0, 'errors' => 0, 'words' => [], 'subs' => []];
            $byPhoneme[$pid]['opportunities']++;

            if (! $o['is_error']) {
                continue;
            }
            $byPhoneme[$pid]['errors']++;
            if (! empty($o['word'])) {
                $byPhoneme[$pid]['words'][] = (string) $o['word'];
            }

            $sub = $o['substituted_phoneme_id'] !== null ? (int) $o['substituted_phoneme_id'] : null;
            if ($sub !== null) {
                $byPhoneme[$pid]['subs'][$sub] ??= ['errors' => 0, 'words' => []];
                $byPhoneme[$pid]['subs'][$sub]['errors']++;
                if (! empty($o['word'])) {
                    $byPhoneme[$pid]['subs'][$sub]['words'][] = (string) $o['word'];
                }
            }
        }

        $touched = [];

        DB::transaction(function () use ($userId, $byPhoneme, &$touched) {
            foreach ($byPhoneme as $phonemeId => $data) {
                $touched[$phonemeId] = $this->apply(
                    $userId, $phonemeId, null,
                    $data['opportunities'], $data['errors'], $data['words'],
                );

                foreach ($data['subs'] as $subId => $subData) {
                    // The denominator stays the phoneme's opportunities, so this
                    // row reads as "how often this sound became that one".
                    $this->apply(
                        $userId, $phonemeId, $subId,
                        $data['opportunities'], $subData['errors'], $subData['words'],
                    );
                }
            }
        });

        return $touched;
    }

    private function apply(
        int $userId,
        int $phonemeId,
        ?int $substitutedId,
        int $opportunities,
        int $errors,
        array $words,
    ): PronunciationError {
        $row = PronunciationError::where('user_id', $userId)
            ->where('phoneme_id', $phonemeId)
            ->when($substitutedId === null,
                fn ($q) => $q->whereNull('substituted_phoneme_id'),
                fn ($q) => $q->where('substituted_phoneme_id', $substitutedId))
            ->lockForUpdate()
            ->first();

        $attemptRate = $opportunities > 0 ? $errors / $opportunities : 0.0;

        if (! $row) {
            $row = new PronunciationError([
                'user_id' => $userId,
                'phoneme_id' => $phonemeId,
                'substituted_phoneme_id' => $substitutedId,
                'occurrence_count' => 0,
                'attempt_count' => 0,
                'first_seen_at' => now(),
            ]);
            $recent = $attemptRate;
        } else {
            $recent = (1 - self::RECENT_WEIGHT) * (float) $row->recent_error_rate
                + self::RECENT_WEIGHT * $attemptRate;
        }

        $row->attempt_count = (int) $row->attempt_count + $opportunities;
        $row->occurrence_count = (int) $row->occurrence_count + $errors;
        $row->error_rate = $row->attempt_count > 0
            ? round($row->occurrence_count / $row->attempt_count, 3)
            : 0.0;
        $row->recent_error_rate = round($recent, 3);
        $row->last_seen_at = now();

        if ($words !== []) {
            $existing = is_array($row->example_words) ? $row->example_words : [];
            $merged = array_values(array_unique(array_merge($words, $existing)));
            $row->example_words = array_slice($merged, 0, self::MAX_EXAMPLE_WORDS);
        }

        // "Resolved" means not currently worth drilling; a fresh error reopens it.
        if ($errors > 0) {
            $row->resolved_at = null;
        } elseif ($row->recent_error_rate <= self::RESOLVED_THRESHOLD && $row->attempt_count >= self::MIN_OPPORTUNITIES) {
            $row->resolved_at ??= now();
        }

        $row->save();

        return $row;
    }

    /**
     * Phonemes the learner persistently gets wrong, worst first.
     *
     * @return Collection<int,PronunciationError>
     */
    public function problemPhonemes(int $userId, int $limit = 10): Collection
    {
        return PronunciationError::with('phoneme')
            ->where('user_id', $userId)
            ->whereNull('substituted_phoneme_id')
            ->whereNull('resolved_at')
            ->where('attempt_count', '>=', self::MIN_OPPORTUNITIES)
            ->where('recent_error_rate', '>=', self::PROBLEM_THRESHOLD)
            ->orderByDesc('recent_error_rate')
            ->orderByDesc('occurrence_count')
            ->limit($limit)
            ->get();
    }

    /**
     * What the learning engine needs to build targeted drills: the problem
     * sound, what it is being replaced with, the words it went wrong in, and
     * minimal pairs that force the contrast.
     *
     * @return array<int,array<string,mixed>>
     */
    public function drillTargets(int $userId, int $limit = 5): array
    {
        $problems = $this->problemPhonemes($userId, $limit);
        if ($problems->isEmpty()) {
            return [];
        }

        $confusions = $this->confusionsFor($userId, $problems->pluck('phoneme_id')->all());
        $pairs = $this->minimalPairsFor($problems->pluck('phoneme_id')->all());

        return $problems->map(fn (PronunciationError $row) => [
            'phoneme_id' => $row->phoneme_id,
            'ipa' => $row->phoneme?->ipa,
            'arpabet' => $row->phoneme?->arpabet,
            'type' => $row->phoneme?->type,
            'articulation_hint' => $row->phoneme?->articulation_hint,
            'error_rate' => (float) $row->error_rate,
            'recent_error_rate' => (float) $row->recent_error_rate,
            'improving' => (float) $row->recent_error_rate < (float) $row->error_rate,
            'occurrence_count' => (int) $row->occurrence_count,
            'attempt_count' => (int) $row->attempt_count,
            'example_words' => $row->example_words ?? [],
            'confused_with' => $confusions[$row->phoneme_id] ?? [],
            'minimal_pairs' => $pairs[$row->phoneme_id] ?? [],
        ])->all();
    }

    /**
     * Everything the profile endpoint returns.
     *
     * @return array<string,mixed>
     */
    public function profileFor(int $userId): array
    {
        $rows = PronunciationError::with('phoneme')
            ->where('user_id', $userId)
            ->whereNull('substituted_phoneme_id')
            ->orderByDesc('recent_error_rate')
            ->get();

        $confusions = $this->confusionsFor($userId, $rows->pluck('phoneme_id')->all());

        return [
            'phonemes' => $rows->map(fn (PronunciationError $row) => [
                'phoneme_id' => $row->phoneme_id,
                'ipa' => $row->phoneme?->ipa,
                'arpabet' => $row->phoneme?->arpabet,
                'type' => $row->phoneme?->type,
                'attempt_count' => (int) $row->attempt_count,
                'occurrence_count' => (int) $row->occurrence_count,
                'error_rate' => (float) $row->error_rate,
                'recent_error_rate' => (float) $row->recent_error_rate,
                'improving' => (float) $row->recent_error_rate < (float) $row->error_rate,
                'is_problem' => $row->resolved_at === null
                    && (int) $row->attempt_count >= self::MIN_OPPORTUNITIES
                    && (float) $row->recent_error_rate >= self::PROBLEM_THRESHOLD,
                'resolved_at' => $row->resolved_at?->toIso8601String(),
                'example_words' => $row->example_words ?? [],
                'confused_with' => $confusions[$row->phoneme_id] ?? [],
                'first_seen_at' => $row->first_seen_at?->toIso8601String(),
                'last_seen_at' => $row->last_seen_at?->toIso8601String(),
            ])->all(),
            'drill_targets' => $this->drillTargets($userId),
            'thresholds' => [
                'min_opportunities' => self::MIN_OPPORTUNITIES,
                'problem_error_rate' => self::PROBLEM_THRESHOLD,
                'resolved_error_rate' => self::RESOLVED_THRESHOLD,
                'recent_window_weight' => self::RECENT_WEIGHT,
            ],
        ];
    }

    /** @return array<int,array<int,array<string,mixed>>> keyed by phoneme id */
    private function confusionsFor(int $userId, array $phonemeIds): array
    {
        if ($phonemeIds === []) {
            return [];
        }

        $out = [];
        $rows = PronunciationError::with('phoneme')
            ->where('user_id', $userId)
            ->whereIn('phoneme_id', $phonemeIds)
            ->whereNotNull('substituted_phoneme_id')
            ->where('occurrence_count', '>', 0)
            ->orderByDesc('occurrence_count')
            ->get();

        $ipa = Phoneme::whereIn('id', $rows->pluck('substituted_phoneme_id')->filter()->all())
            ->pluck('ipa', 'id');

        foreach ($rows as $row) {
            $out[$row->phoneme_id][] = [
                'phoneme_id' => (int) $row->substituted_phoneme_id,
                'ipa' => $ipa[$row->substituted_phoneme_id] ?? null,
                'occurrence_count' => (int) $row->occurrence_count,
                'error_rate' => (float) $row->error_rate,
            ];
        }

        return $out;
    }

    /** @return array<int,array<int,array<string,mixed>>> keyed by phoneme id */
    private function minimalPairsFor(array $phonemeIds): array
    {
        $phonemeIds = array_map('intval', $phonemeIds);
        if ($phonemeIds === []) {
            return [];
        }

        $pairs = MinimalPair::query()
            ->join('pronunciation_items as a', 'a.id', '=', 'minimal_pairs.item_a_id')
            ->join('pronunciation_items as b', 'b.id', '=', 'minimal_pairs.item_b_id')
            ->where(fn ($q) => $q->whereIn('minimal_pairs.phoneme_a_id', $phonemeIds)
                ->orWhereIn('minimal_pairs.phoneme_b_id', $phonemeIds))
            ->get([
                'minimal_pairs.id', 'minimal_pairs.phoneme_a_id', 'minimal_pairs.phoneme_b_id',
                'a.text as text_a', 'a.ipa as ipa_a', 'b.text as text_b', 'b.ipa as ipa_b',
            ]);

        $out = [];
        foreach ($pairs as $pair) {
            foreach ([$pair->phoneme_a_id, $pair->phoneme_b_id] as $pid) {
                if (! in_array((int) $pid, $phonemeIds, true)) {
                    continue;
                }
                $out[$pid][] = [
                    'minimal_pair_id' => $pair->id,
                    'a' => ['text' => $pair->text_a, 'ipa' => $pair->ipa_a],
                    'b' => ['text' => $pair->text_b, 'ipa' => $pair->ipa_b],
                ];
            }
        }

        return $out;
    }
}
