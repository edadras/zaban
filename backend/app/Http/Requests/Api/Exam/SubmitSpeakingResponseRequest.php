<?php

namespace App\Http\Requests\Api\Exam;

use Illuminate\Foundation\Http\FormRequest;

/** One spoken turn in the AI examiner interview. */
class SubmitSpeakingResponseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'speech_attempt_id' => ['required', 'integer', 'exists:speech_attempts,id'],
        ];
    }
}
