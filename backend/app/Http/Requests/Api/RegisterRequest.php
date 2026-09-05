<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:190', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'target_language' => ['nullable', 'string', Rule::exists('languages', 'code')->where('is_learnable', true)],
            'native_language' => ['nullable', 'string', Rule::exists('languages', 'code')],
            'country_code' => ['nullable', 'string', 'size:2'],
            'timezone' => ['nullable', 'string', 'max:64', 'timezone'],
            'locale' => ['nullable', 'string', 'max:12'],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'learning_objective' => ['nullable', Rule::in([
                'general_english', 'conversation', 'travel', 'work', 'academic',
                'ielts', 'toefl', 'cambridge', 'business',
            ])],
            'daily_target_minutes' => ['nullable', 'integer', 'min:5', 'max:180'],
        ];
    }
}
