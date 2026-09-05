<?php

namespace App\Http\Resources\Exam;

use App\Models\ExamScoreBand;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\ExamType */
class ExamTypeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'score' => [
                'type' => $this->score_type,
                'min' => (float) $this->score_min,
                'max' => (float) $this->score_max,
                'step' => (float) $this->score_step,
            ],
            'total_minutes' => $this->total_minutes !== null ? (int) $this->total_minutes : null,
            'sections' => ExamSectionResource::collection($this->whenLoaded('sections')),
            'cefr_mapping' => $this->whenLoaded('bands', fn () => $this->bands
                ->sortBy('score_from')
                ->map(fn (ExamScoreBand $b) => [
                    'score_from' => (float) $b->score_from,
                    'score_to' => (float) $b->score_to,
                    'cefr' => $b->cefrLevel?->code,
                    'cefr_name' => $b->cefrLevel?->name,
                ])->values()),
        ];
    }
}
