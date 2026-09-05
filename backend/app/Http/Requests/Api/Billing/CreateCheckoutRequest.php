<?php

namespace App\Http\Requests\Api\Billing;

use App\Billing\BillingConfig;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateCheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'plan_code' => ['required', 'string', 'max:48', Rule::exists('plans', 'code')->where('is_active', true)],
            'gateway' => ['nullable', 'string', Rule::in(array_keys(BillingConfig::gateways()))],
            'currency' => ['nullable', 'string', 'size:3'],
            'country_code' => ['nullable', 'string', 'size:2'],
            'coupon_code' => ['nullable', 'string', 'max:64'],
            'success_url' => ['required', 'url', 'max:2048'],
            'cancel_url' => ['required', 'url', 'max:2048'],
            'buyer' => ['nullable', 'array'],
            'buyer.name' => ['nullable', 'string', 'max:120'],
            'buyer.surname' => ['nullable', 'string', 'max:120'],
            'buyer.phone' => ['nullable', 'string', 'max:32'],
            'buyer.identity_number' => ['nullable', 'string', 'max:32'],
            'buyer.address' => ['nullable', 'string', 'max:255'],
            'buyer.city' => ['nullable', 'string', 'max:64'],
            'buyer.country' => ['nullable', 'string', 'max:64'],
            'buyer.zip_code' => ['nullable', 'string', 'max:16'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('currency'))) {
            $this->merge(['currency' => strtoupper($this->input('currency'))]);
        }
    }
}
