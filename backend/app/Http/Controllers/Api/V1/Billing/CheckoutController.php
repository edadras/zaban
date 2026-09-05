<?php

namespace App\Http\Controllers\Api\V1\Billing;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Api\Billing\CreateCheckoutRequest;
use App\Models\Plan;
use App\Services\Billing\CouponService;
use App\Services\Billing\SubscriptionService;

class CheckoutController extends ApiController
{
    public function __construct(
        private SubscriptionService $subscriptions,
        private CouponService $coupons,
    ) {}

    public function store(CreateCheckoutRequest $request)
    {
        $user = $request->user();
        $plan = Plan::where('code', $request->string('plan_code'))->firstOrFail();
        $gateway = (string) ($request->input('gateway') ?: $this->subscriptions->defaultGateway());

        $price = $this->subscriptions->resolvePrice(
            $plan,
            $request->input('currency'),
            $request->input('country_code'),
            $gateway,
        );
        if (! $price) {
            return $this->fail('price_unavailable', 'That plan is not sold in the requested currency.', 422);
        }

        $coupon = null;
        if ($request->filled('coupon_code')) {
            $evaluation = $this->coupons->evaluate(
                (string) $request->string('coupon_code'),
                (int) $user->id,
                (int) $price->amount,
                $price->currency,
            );
            if (! $evaluation->ok) {
                return $this->fail($evaluation->reasonCode ?? 'coupon_invalid', $evaluation->message ?? 'That coupon cannot be used.', 422);
            }
            $coupon = $evaluation->coupon;
        }

        $outcome = $this->subscriptions->createCheckout(
            user: $user,
            plan: $plan,
            price: $price,
            gatewayCode: $gateway,
            successUrl: (string) $request->string('success_url'),
            cancelUrl: (string) $request->string('cancel_url'),
            coupon: $coupon,
            buyer: (array) $request->input('buyer', []),
            ipAddress: $request->ip(),
        );

        if (! $outcome->ok) {
            // 502: the request was fine, the gateway was not.
            return $this->fail(
                $outcome->result->errorCode ?? 'checkout_failed',
                $outcome->result->error ?? 'The payment gateway could not start a checkout.',
                502,
                ['attempt_reference' => $outcome->attempt->idempotency_key],
            );
        }

        return $this->created([
            'gateway' => $gateway,
            'reference' => $outcome->result->reference,
            'redirect_url' => $outcome->result->redirectUrl,
            'html_content' => $outcome->result->htmlContent,
            'amount' => (int) $outcome->attempt->amount,
            'currency' => $outcome->attempt->currency,
            'attempt_reference' => $outcome->attempt->idempotency_key,
        ]);
    }
}
