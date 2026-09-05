<?php

namespace App\Http\Controllers\Api\V1\Billing;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Api\Billing\ApplyCouponRequest;
use App\Models\Plan;
use App\Services\Billing\CouponService;
use App\Services\Billing\SubscriptionService;

class CouponController extends ApiController
{
    public function __construct(
        private CouponService $coupons,
        private SubscriptionService $subscriptions,
    ) {}

    /**
     * Price a coupon against a plan. Nothing is redeemed here - redemption
     * happens when the payment actually succeeds, so an abandoned checkout
     * cannot burn a single-use code.
     */
    public function store(ApplyCouponRequest $request)
    {
        $plan = Plan::where('code', $request->string('plan_code'))->firstOrFail();
        $price = $this->subscriptions->resolvePrice(
            $plan,
            $request->input('currency'),
            $request->input('country_code'),
        );
        if (! $price) {
            return $this->fail('price_unavailable', 'That plan is not sold in the requested currency.', 422);
        }

        $evaluation = $this->coupons->evaluate(
            (string) $request->string('code'),
            (int) $request->user()->id,
            (int) $price->amount,
            $price->currency,
        );

        if (! $evaluation->ok) {
            return $this->fail($evaluation->reasonCode ?? 'coupon_invalid', $evaluation->message ?? 'That coupon cannot be used.', 422);
        }

        return $this->ok([
            'code' => $evaluation->coupon->code,
            'discount_type' => $evaluation->coupon->discount_type,
            'discount_value' => (int) $evaluation->coupon->discount_value,
            'plan_code' => $plan->code,
            'currency' => strtoupper($price->currency),
            'amount' => (int) $price->amount,
            'discount' => $evaluation->discount,
            'amount_due' => $evaluation->amountAfterDiscount,
        ]);
    }
}
