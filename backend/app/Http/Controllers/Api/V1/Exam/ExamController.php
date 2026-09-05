<?php

namespace App\Http\Controllers\Api\V1\Exam;

use App\Http\Controllers\Api\V1\ApiController;
use App\Models\ExamAttempt;
use App\Services\Exam\ExamException;
use Illuminate\Http\Request;

/**
 * Shared plumbing for the exam endpoints: ownership checks and turning the
 * service layer's refusals into stable error codes instead of 500s.
 */
abstract class ExamController extends ApiController
{
    protected function owned(Request $request, ExamAttempt $attempt): ExamAttempt
    {
        abort_if($attempt->user_id !== $request->user()->id, 403, 'This exam attempt belongs to another learner.');

        return $attempt->load(['examType', 'sectionAttempts.section.skill']);
    }

    /** @param  callable(): mixed  $work */
    protected function guard(callable $work)
    {
        try {
            return $work();
        } catch (ExamException $e) {
            return $this->fail($e->errorCode, $e->getMessage(), $e->status);
        }
    }
}
