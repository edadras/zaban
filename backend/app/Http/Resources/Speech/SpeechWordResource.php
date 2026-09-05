<?php

namespace App\Http\Resources\Speech;

use App\Models\Phoneme;
use App\Models\SpeechWord;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SpeechWord
 */
class SpeechWordResource extends JsonResource
{
    /**
     * The phoneme inventory is a few dozen rows, so one lookup serves a whole
     * response. Keyed by request so a long-lived worker never serves a stale map.
     *
     * @var array{key:?int, map:array<int,string>}
     */
    private static array $ipaCache = ['key' => null, 'map' => []];

    public function toArray(Request $request): array
    {
        return [
            'position' => (int) $this->position,
            'expected_word' => $this->expected_word,
            'spoken_word' => $this->spoken_word,
            'outcome' => $this->outcome,
            'start_ms' => $this->start_ms !== null ? (int) $this->start_ms : null,
            'end_ms' => $this->end_ms !== null ? (int) $this->end_ms : null,
            'confidence' => $this->confidence,
            // Null means no forced alignment ran for this word, not a zero score.
            'accuracy_score' => $this->accuracy_score,
            'stress_correct' => $this->stress_correct,
            'phonemes' => $this->whenLoaded('phonemes', fn () => $this->phonemes->map(fn ($p) => [
                'position' => (int) $p->position,
                'expected' => self::ipa($request, $p->expected_phoneme_id),
                'actual' => self::ipa($request, $p->actual_phoneme_id),
                'start_ms' => $p->start_ms !== null ? (int) $p->start_ms : null,
                'end_ms' => $p->end_ms !== null ? (int) $p->end_ms : null,
                'accuracy_score' => $p->accuracy_score,
                'is_error' => (bool) $p->is_error,
            ])->values()),
        ];
    }

    private static function ipa(Request $request, ?int $id): ?string
    {
        if ($id === null) {
            return null;
        }

        $key = spl_object_id($request);
        if (self::$ipaCache['key'] !== $key) {
            self::$ipaCache = ['key' => $key, 'map' => Phoneme::pluck('ipa', 'id')->all()];
        }

        return self::$ipaCache['map'][$id] ?? null;
    }
}
