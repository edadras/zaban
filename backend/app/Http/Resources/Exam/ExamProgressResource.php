<?php

namespace App\Http\Resources\Exam;

use App\Services\Exam\ExamEstimate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Wraps ExamAnalyticsService::progress. */
class ExamProgressResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = $this->resource;
        $anyAi = collect($data['points'])->contains(fn (array $p) => (bool) $p['is_ai_estimated']);

        return [
            'attempts' => $data['attempts'],
            'points' => $data['points'],
            'latest' => $data['latest'],
            'best' => $data['best'],
            'change' => $data['change'],
            'weakest_skills' => $data['weakest_skills'],
            'estimate' => ExamEstimate::label($anyAi),
        ];
    }
}
