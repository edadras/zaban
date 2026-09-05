<?php

namespace App\Http\Requests\Api\Speech;

use Illuminate\Foundation\Http\FormRequest;

class StoreSpeechAttemptRequest extends FormRequest
{
    /** Roughly five minutes of compressed speech; longer takes go to the upload flow. */
    private const MAX_KILOBYTES = 25600;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'audio' => [
                'required',
                'file',
                'max:'.self::MAX_KILOBYTES,
                'mimetypes:audio/wav,audio/x-wav,audio/wave,audio/vnd.wave,audio/mpeg,audio/mp3,'
                    .'audio/mp4,audio/m4a,audio/x-m4a,audio/aac,audio/ogg,audio/opus,audio/webm,'
                    .'audio/flac,audio/x-flac,video/webm',
            ],
            // The text the learner was asked to say. Without it the attempt is
            // scored as open speech: no completeness, no pronunciation.
            'expected_text' => ['nullable', 'string', 'max:2000'],
            'duration_ms' => ['nullable', 'integer', 'min:0', 'max:1800000'],
            'exercise_id' => ['nullable', 'integer', 'exists:exercises,id'],
            'production_prompt_id' => ['nullable', 'integer', 'exists:production_prompts,id'],
            'pronunciation_item_id' => ['nullable', 'integer', 'exists:pronunciation_items,id'],
            'learning_session_id' => ['nullable', 'integer', 'exists:learning_sessions,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'audio.mimetypes' => 'The recording must be an audio file (wav, mp3, m4a, ogg, opus, flac or webm).',
        ];
    }
}
