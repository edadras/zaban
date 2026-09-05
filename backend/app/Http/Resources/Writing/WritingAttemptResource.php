<?php

namespace App\Http\Resources\Writing;

use Illuminate\Http\Resources\Json\JsonResource;

class WritingAttemptResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'source' => $this->source,
            'word_count' => $this->word_count,
            'text' => $this->text,

            /*
             * Only meaningful for a photographed page, and deliberately kept
             * distinct from `text`: this is what the machine read, `text` is
             * what the learner stands behind. The client shows the first for
             * correction and submits the second.
             */
            'recognition' => $this->when($this->source === 'photo', fn () => [
                'text' => $this->recognised_text,
                'confidence' => $this->recognition_confidence,
                'confirmed' => (bool) $this->text_confirmed,
                'needs_careful_check' => $this->recognition_confidence !== null
                    && $this->recognition_confidence < \App\Services\Writing\HandwritingRecogniser::LOW_CONFIDENCE,
            ]),

            'scores' => $this->when($this->status === 'scored', fn () => [
                'overall' => $this->overall_score,
                'task_achievement' => $this->task_achievement_score,
                'coherence' => $this->coherence_score,
                'grammar' => $this->grammar_score,
                'vocabulary' => $this->vocabulary_score,
                'mechanics' => $this->mechanics_score,
            ]),

            'corrections' => $this->when($this->status === 'scored', fn () => $this->corrections ?? []),
            'feedback' => $this->when($this->status === 'scored', fn () => $this->feedback),
            'error' => $this->error,
            'scored_at' => $this->scored_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
