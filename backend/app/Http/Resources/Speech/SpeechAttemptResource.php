<?php

namespace App\Http\Resources\Speech;

use App\Models\SpeechAttempt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SpeechAttempt
 *
 * A null score is a first-class value here: it means "not measured", and the
 * reason is in feedback.not_measured. The client must render it as such rather
 * than as a zero (spec 21).
 */
class SpeechAttemptResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'error' => $this->error,
            'expected_text' => $this->expected_text,
            'transcript' => $this->transcript,
            'duration_ms' => $this->duration_ms !== null ? (int) $this->duration_ms : null,
            'scores' => [
                'overall' => $this->overall_score,
                'pronunciation' => $this->pronunciation_score,
                'fluency' => $this->fluency_score,
                'grammar' => $this->grammar_score,
                'vocabulary' => $this->vocabulary_score,
                'completeness' => $this->completeness_score,
            ],
            'fluency' => [
                'speech_rate_wpm' => $this->speech_rate_wpm,
                'pause_count' => $this->pause_count !== null ? (int) $this->pause_count : null,
                'total_pause_ms' => $this->total_pause_ms !== null ? (int) $this->total_pause_ms : null,
                'filler_count' => $this->filler_count !== null ? (int) $this->filler_count : null,
            ],
            'engines' => [
                'stt_provider' => $this->stt_provider,
                'aligner' => $this->aligner,
                'phoneme_scoring' => $this->pronunciation_score !== null,
            ],
            'audio' => [
                'available' => ! $this->audio_deleted && $this->media_asset_id !== null,
                'deleted' => (bool) $this->audio_deleted,
                'delete_after' => $this->audio_delete_after?->toIso8601String(),
            ],
            'feedback' => $this->feedback,
            'words' => SpeechWordResource::collection($this->whenLoaded('words')),
            'scored_at' => $this->scored_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
