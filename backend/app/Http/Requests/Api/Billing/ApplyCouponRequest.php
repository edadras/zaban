<?php

namespace App\Http\Requests\Api\Billing;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ApplyCouponRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:64'],
            // The coupon is priced against a specific plan, so the client says which.
            'plan_code' => ['required', 'string', 'max:48', Rule::exists('plans', 'code')->where('is_active', true)],
            'currency' => ['nullable', 'string', 'size:3'],
            'country_code' => ['nullable', 'string', 'size:2'],
        ];
    }
}
