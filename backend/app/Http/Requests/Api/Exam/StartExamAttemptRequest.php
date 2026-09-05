<?php

namespace App\Http\Requests\Api\Exam;

use App\Services\Exam\ExamService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StartExamAttemptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'exam_type_id' => ['required', 'integer', Rule::exists('exam_types', 'id')->where('is_active', true)],
            'mode' => ['sometimes', 'string', Rule::in(ExamService::MODES)],
            // Required only for a single-section rehearsal; the service checks it
            // really belongs to the chosen exam.
            'exam_section_id' => ['nullable', 'integer', 'exists:exam_sections,id', 'required_if:mode,section'],
        ];
    }
}
