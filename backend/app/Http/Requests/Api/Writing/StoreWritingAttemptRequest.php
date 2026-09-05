<?php

namespace App\Http\Requests\Api\Writing;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreWritingAttemptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'text' => ['nullable', 'string', 'max:20000'],
            'page' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp,heic', 'max:12288'],
            'production_prompt_id' => ['nullable', 'integer', 'exists:production_prompts,id'],
            'exercise_id' => ['nullable', 'integer', 'exists:exercises,id'],
            'lesson_id' => ['nullable', 'integer', 'exists:lessons,id'],
            'learning_session_id' => ['nullable', 'integer'],
            'cefr_level_id' => ['nullable', 'integer', 'exists:cefr_levels,id'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                // Exactly one intake path. Both would leave it ambiguous which
                // is the learner's actual work; neither is nothing to mark.
                $hasText = trim((string) $this->input('text')) !== '';
                $hasPage = $this->hasFile('page');

                if (! $hasText && ! $hasPage) {
                    $validator->errors()->add('text', 'Send either written text or a photo of the page.');
                }

                if ($hasText && $hasPage) {
                    $validator->errors()->add('text', 'Send either text or a photo, not both.');
                }
            },
        ];
    }
}
