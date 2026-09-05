<?php

namespace App\Http\Resources\Billing;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\PlanEntitlement */
class PlanEntitlementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'feature' => $this->feature,
            'enabled' => (bool) $this->is_enabled,
            'limit' => $this->limit_value !== null ? (int) $this->limit_value : null,
            'period' => $this->limit_period,
        ];
    }
}
