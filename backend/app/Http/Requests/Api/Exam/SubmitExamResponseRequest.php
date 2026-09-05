<?php

namespace App\Http\Requests\Api\Exam;

use Illuminate\Foundation\Http\FormRequest;

/**
 * One task's answer. The shape depends on the section: objective tasks send
 * answers keyed by exercise id, writing sends text, speaking sends the id of an
 * already-uploaded recording.
 */
class SubmitExamResponseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'answers' => ['sometimes', 'array'],
            'text' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'speech_attempt_id' => ['sometimes', 'nullable', 'integer', 'exists:speech_attempts,id'],
            'speech_attempt_ids' => ['sometimes', 'array', 'max:20'],
            'speech_attempt_ids.*' => ['integer', 'exists:speech_attempts,id'],
            'questions' => ['sometimes', 'array', 'max:20'],
            'questions.*' => ['string', 'max:500'],
            'seconds_used' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:36000'],
        ];
    }

    public function payload(): array
    {
        return $this->safe()->except('seconds_used');
    }

    public function secondsUsed(): ?int
    {
        $value = $this->input('seconds_used');

        return $value === null ? null : (int) $value;
    }
}
