<?php

namespace App\Http\Controllers\Api\V1\Billing;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Resources\Billing\PlanResource;
use App\Models\Plan;
use Illuminate\Http\Request;

class PlanController extends ApiController
{
    public function index(Request $request)
    {
        $plans = Plan::with(['prices' => fn ($q) => $q->where('is_active', true), 'entitlements'])
            ->where('is_active', true)
            ->where('is_public', true)
            ->orderBy('position')
            ->get();

        return $this->ok(PlanResource::collection($plans));
    }

    public function show(Request $request, string $code)
    {
        $plan = Plan::with(['prices' => fn ($q) => $q->where('is_active', true), 'entitlements'])
            ->where('code', $code)
            ->where('is_active', true)
            ->first();

        if (! $plan) {
            return $this->fail('plan_not_found', 'That plan does not exist.', 404);
        }

        return $this->ok(new PlanResource($plan));
    }
}
