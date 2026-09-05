<?php

namespace App\Http\Resources\Exam;

use App\Models\ExamScore;
use App\Services\Exam\ExamEstimate;
use App\Services\Exam\ExamService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The result screen: overall estimate, per-criterion detail, CEFR, and the four
 * things that make the next attempt better.
 *
 * Wraps the array assembled by ExamAttemptController::results.
 */
class ExamResultResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $attempt = $this->resource['attempt'];

        return [
            'attempt' => (new ExamAttemptResource($attempt))->toArray($request),
            'overall' => [
                'estimated_score' => $attempt->estimated_score !== null ? (float) $attempt->estimated_score : null,
                'cefr' => $this->resource['cefr'],
                'cefr_name' => $this->resource['cefr_name'],
                'scale' => $this->resource['scale'],
                'unavailable_reason' => $attempt->estimated_score === null ? 'incomplete_sections' : null,
            ],
            'skills' => $this->resource['skills'],
            'criteria' => collect($this->resource['criteria'])
                ->map(fn (ExamScore $s) => [
                    'section_attempt_id' => $s->exam_section_attempt_id,
                    'criterion' => $s->criterion,
                    'score' => (float) $s->score,
                    'rationale' => $s->rationale,
                    'scale' => $s->evidence['scale'] ?? null,
                    'evidence' => array_values($s->evidence['quotes'] ?? []),
                    'per_task' => $s->evidence['per_task'] ?? [],
                    'is_ai_estimated' => (bool) ($s->evidence['is_ai_estimated'] ?? true),
                ])->values(),
            'time_management' => $attempt->time_management,
            'question_types' => $this->resource['question_types'],
            'mistakes' => $this->resource['mistakes'],
            'estimate' => ExamEstimate::label(
                (bool) $attempt->is_ai_estimated,
                $attempt->sectionAttempts
                    ->where('status', ExamService::STATUS_PROJECTED)
                    ->map(fn ($sa) => $sa->section?->code)->filter()->values()->all(),
            ),
        ];
    }
}
