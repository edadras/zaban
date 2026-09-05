<?php

namespace App\Http\Resources\Exam;

use App\Services\Exam\SectionScoring;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\ExamSection */
class ExamSectionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $scoring = SectionScoring::for($this->resource);

        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'position' => (int) $this->position,
            'skill' => $this->whenLoaded('skill', fn () => $this->skill?->code),
            'duration_minutes' => (int) $this->duration_minutes,
            'question_count' => $this->question_count !== null ? (int) $this->question_count : null,
            'scoring' => [
                'mode' => $scoring->mode(),
                'aggregation' => $scoring->aggregation(),
                'scale' => $scoring->sectionScale(),
                // The raw-to-scale conversion table is deliberately not exposed:
                // it is marking machinery, not something a candidate acts on.
                'criteria' => array_map(fn (array $c) => [
                    'code' => $c['code'],
                    'name' => $c['name'],
                    'weight' => (float) ($c['weight'] ?? 1.0),
                    'descriptor' => $c['descriptor'] ?? null,
                ], $scoring->criteria()),
                'criterion_scale' => $scoring->criteria() ? $scoring->criterionScale() : null,
                'parts' => $scoring->parts(),
            ],
            'task_types' => $this->whenLoaded('taskTypes', fn () => $this->taskTypes->map(fn ($t) => [
                'code' => $t->code,
                'name' => $t->name,
                'description' => $t->description,
                'typical_count' => $t->typical_count !== null ? (int) $t->typical_count : null,
            ])->values()),
        ];
    }
}
