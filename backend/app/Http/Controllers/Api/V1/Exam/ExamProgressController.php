<?php

namespace App\Http\Controllers\Api\V1\Exam;

use App\Http\Resources\Exam\ExamProgressResource;
use App\Services\Exam\ExamAnalyticsService;
use Illuminate\Http\Request;

/** Estimated band over time, per exam and per skill. */
class ExamProgressController extends ExamController
{
    public function index(Request $request, ExamAnalyticsService $analytics)
    {
        $progress = $analytics->progress(
            $request->user()->id,
            $request->filled('exam_type_id') ? $request->integer('exam_type_id') : null,
        );

        return $this->ok(new ExamProgressResource($progress));
    }
}
