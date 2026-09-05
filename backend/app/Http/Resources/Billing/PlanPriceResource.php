<?php

namespace App\Http\Resources\Billing;

use App\Models\PlanPrice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PlanPrice */
class PlanPriceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'currency' => strtoupper($this->currency),
            'amount' => (int) $this->amount,
            'amount_display' => number_format($this->amount / 100, 2, '.', ''),
            'country_code' => $this->country_code,
            'gateway' => $this->gateway,
        ];
    }
}
