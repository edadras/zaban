<?php

namespace App\Services\Exam;

use RuntimeException;

/** A refusal the API should turn into a specific error code, not a 500. */
class ExamException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }

    public static function sectionExpired(string $section): self
    {
        return new self('exam_section_expired', "Time for the {$section} section has run out; the section is closed.", 409);
    }

    public static function attemptExpired(): self
    {
        return new self('exam_attempt_expired', 'The overall time limit for this mock exam has passed.', 409);
    }

    public static function notInProgress(): self
    {
        return new self('exam_attempt_not_in_progress', 'This attempt is no longer in progress.', 409);
    }

    public static function taskNotAvailable(): self
    {
        return new self('exam_task_not_available', 'That task is not part of the section currently in progress.', 422);
    }

    public static function noContent(string $exam): self
    {
        return new self('exam_no_content', "No published tasks are available for {$exam} yet.", 422);
    }

    public static function invalidMode(string $mode): self
    {
        return new self('exam_invalid_mode', "Unknown exam mode \"{$mode}\".", 422);
    }

    public static function sectionMismatch(): self
    {
        return new self('exam_section_mismatch', 'That section does not belong to this exam.', 422);
    }

    public static function notSpeaking(): self
    {
        return new self('exam_not_speaking_section', 'The section in progress is not a speaking section.', 422);
    }
}
