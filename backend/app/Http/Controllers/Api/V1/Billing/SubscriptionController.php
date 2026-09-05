<?php

namespace App\Http\Controllers\Api\V1\Billing;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Api\Billing\CancelSubscriptionRequest;
use App\Http\Requests\Api\Billing\ChangePlanRequest;
use App\Http\Resources\Billing\SubscriptionResource;
use App\Models\Plan;
use App\Services\Billing\EntitlementService;
use App\Services\Billing\SubscriptionService;
use Illuminate\Http\Request;

class SubscriptionController extends ApiController
{
    public function __construct(
        private SubscriptionService $subscriptions,
        private EntitlementService $entitlements,
    ) {}

    /** The client renders from this; it never decides access itself. */
    public function show(Request $request)
    {
        $userId = (int) $request->user()->id;
        $subscription = $this->subscriptions->currentFor($userId);

        return $this->ok([
            'subscription' => $subscription ? new SubscriptionResource($subscription->loadMissing('plan')) : null,
            'plan' => $this->entitlements->planFor($userId)?->code,
            'entitlements' => $this->entitlements->snapshot($userId),
        ]);
    }

    public function cancel(CancelSubscriptionRequest $request)
    {
        $subscription = $this->subscriptions->currentFor((int) $request->user()->id);
        if (! $subscription) {
            return $this->fail('no_subscription', 'You have no active subscription.', 404);
        }

        $result = $this->subscriptions->cancel($subscription, (bool) $request->boolean('immediately'));
        if (! $result->ok) {
            return $this->fail($result->errorCode ?? 'cancel_failed', $result->error ?? 'The subscription could not be cancelled.', 422);
        }

        return $this->ok(new SubscriptionResource($subscription->refresh()->loadMissing('plan')));
    }

    public function resume(Request $request)
    {
        $subscription = $this->subscriptions->currentFor((int) $request->user()->id);
        if (! $subscription) {
            return $this->fail('no_subscription', 'You have no active subscription.', 404);
        }

        $result = $this->subscriptions->resume($subscription);
        if (! $result->ok) {
            return $this->fail($result->errorCode ?? 'resume_failed', $result->error ?? 'The subscription could not be resumed.', 422);
        }

        return $this->ok(new SubscriptionResource($subscription->refresh()->loadMissing('plan')));
    }

    public function changePlan(ChangePlanRequest $request)
    {
        $subscription = $this->subscriptions->currentFor((int) $request->user()->id);
        if (! $subscription) {
            return $this->fail('no_subscription', 'You have no active subscription.', 404);
        }

        $plan = Plan::where('code', $request->string('plan_code'))->firstOrFail();
        $price = $this->subscriptions->resolvePrice(
            $plan,
            $request->input('currency'),
            $request->input('country_code'),
            $subscription->gateway,
        );

        $result = $this->subscriptions->changePlan($subscription, $plan, $price);
        if (! $result->ok) {
            return $this->fail($result->errorCode ?? 'change_plan_failed', $result->error ?? 'The plan could not be changed.', 422);
        }

        return $this->ok(new SubscriptionResource($subscription->refresh()->loadMissing('plan')));
    }
}
