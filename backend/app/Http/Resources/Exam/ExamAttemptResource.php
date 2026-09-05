<?php

namespace App\Http\Resources\Exam;

use App\Models\ExamSectionAttempt;
use App\Services\Exam\ExamEstimate;
use App\Services\Exam\ExamService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\ExamAttempt */
class ExamAttemptResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $projected = $this->projectedSections();

        return [
            'id' => $this->id,
            'exam_type' => $this->whenLoaded('examType', fn () => [
                'id' => $this->examType->id,
                'code' => $this->examType->code,
                'name' => $this->examType->name,
                'score_type' => $this->examType->score_type,
            ]),
            'mode' => $this->mode,
            'status' => $this->status,
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'duration_seconds' => (int) $this->duration_seconds,
            'estimated_score' => $this->estimated_score !== null ? (float) $this->estimated_score : null,
            'sections' => $this->whenLoaded('sectionAttempts', fn () => $this->sectionAttempts
                ->sortBy(fn (ExamSectionAttempt $sa) => $sa->section?->position)
                ->map(fn (ExamSectionAttempt $sa) => [
                    'id' => $sa->id,
                    'code' => $sa->section?->code,
                    'name' => $sa->section?->name,
                    'status' => $sa->status,
                    'estimated_score' => $sa->estimated_score !== null ? (float) $sa->estimated_score : null,
                    'raw_score' => $sa->raw_score !== null ? (float) $sa->raw_score : null,
                    'duration_seconds' => (int) $sa->duration_seconds,
                    'ran_out_of_time' => (bool) $sa->ran_out_of_time,
                    'is_projected' => $sa->status === ExamService::STATUS_PROJECTED,
                ])->values()),
            // Never let a score leave the API without the words that stop it
            // being mistaken for a real result.
            'estimate' => ExamEstimate::label((bool) $this->is_ai_estimated, $projected),
        ];
    }

    /** @return string[] */
    private function projectedSections(): array
    {
        if (! $this->relationLoaded('sectionAttempts')) {
            return [];
        }

        return $this->sectionAttempts
            ->where('status', ExamService::STATUS_PROJECTED)
            ->map(fn (ExamSectionAttempt $sa) => $sa->section?->code)
            ->filter()->values()->all();
    }
}
