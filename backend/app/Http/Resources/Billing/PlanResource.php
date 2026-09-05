<?php

namespace App\Http\Resources\Billing;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Plan */
class PlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'interval' => $this->interval,
            'interval_count' => (int) $this->interval_count,
            'trial_days' => (int) $this->trial_days,
            'position' => (int) $this->position,
            'prices' => PlanPriceResource::collection($this->whenLoaded('prices')),
            'entitlements' => PlanEntitlementResource::collection($this->whenLoaded('entitlements')),
        ];
    }
}
