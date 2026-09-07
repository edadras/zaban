<?php

namespace App\Services\Classroom;

use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Something a coach or learner did that the classroom rules do not allow.
 *
 * Renders itself, so the controllers stay a list of calls rather than a list of
 * try/catch blocks.
 */
class ClassroomException extends RuntimeException
{
    public function __construct(string $message, private readonly int $status = 422)
    {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return ApiResponse::error('classroom_rule', $this->getMessage(), $this->status);
    }
}
