<?php

namespace App\Services\Billing;

use App\Billing\BillingConfig;
use App\Billing\Support\CheckoutOutcome;
use App\Billing\Support\CheckoutRequest;
use App\Billing\Support\CheckoutResult;
use App\Billing\Support\GatewayResult;
use App\Models\Coupon;
use App\Models\PaymentAttempt;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Owns the subscription lifecycle: opening a checkout, swapping plans,
 * cancelling, resuming, trials, and pulling gateway state back into our tables.
 *
 * A subscription only becomes `active` when the gateway says money moved (via
 * WebhookService) - never because a checkout was opened.
 */
class SubscriptionService
{
    public function __construct(
        private GatewayManager $gateways,
        private CouponService $coupons,
        private EntitlementService $entitlements,
    ) {}

    public function currentFor(int $userId): ?Subscription
    {
        return Subscription::with('plan')
            ->where('user_id', $userId)
            ->whereIn('status', ['trialing', 'active', 'past_due', 'paused'])
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Open a checkout at the gateway and record the attempt.
     *
     * @param  array<string, string>  $buyer
     */
    public function createCheckout(
        User $user,
        Plan $plan,
        PlanPrice $price,
        string $gatewayCode,
        string $successUrl,
        string $cancelUrl,
        ?Coupon $coupon = null,
        array $buyer = [],
        ?string $ipAddress = null,
    ): CheckoutOutcome {
        $amount = (int) $price->amount;
        $discount = $coupon ? $this->coupons->discountFor($coupon, $amount) : 0;
        $payable = max(0, $amount - $discount);
        $trialDays = $this->trialDaysFor($user, $plan);

        $attempt = PaymentAttempt::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'plan_price_id' => $price->id,
            'coupon_id' => $coupon?->id,
            'gateway' => $gatewayCode,
            'idempotency_key' => $this->idempotencyKey(),
            'status' => 'initiated',
            'amount' => $payable,
            'currency' => strtoupper($price->currency),
            'metadata' => [
                'plan_code' => $plan->code,
                'discount' => $discount,
                'trial_days' => $trialDays,
                'success_url' => $successUrl,
                'cancel_url' => $cancelUrl,
            ],
        ]);

        $gateway = $this->gateways->make($gatewayCode);
        if (! $gateway) {
            return $this->failAttempt($attempt, CheckoutResult::failure('unknown_gateway', "Unknown payment gateway [{$gatewayCode}]."));
        }
        if (! $gateway->isAvailable()) {
            return $this->failAttempt($attempt, CheckoutResult::failure('gateway_unavailable', "The {$gatewayCode} gateway is not configured."));
        }

        $result = $gateway->createCheckout(new CheckoutRequest(
            userId: (int) $user->id,
            planCode: $plan->code,
            planName: $plan->name,
            amount: $payable,
            currency: strtoupper($price->currency),
            idempotencyKey: $attempt->idempotency_key,
            successUrl: $successUrl,
            cancelUrl: $cancelUrl,
            recurring: $plan->interval !== 'lifetime',
            gatewayPriceId: $price->gateway_price_id,
            gatewayCustomerId: $this->gatewayCustomerId($user->id, $gatewayCode),
            gatewayCouponId: null,
            trialDays: $trialDays,
            customerEmail: $user->email,
            ipAddress: $ipAddress,
            buyer: $buyer,
            metadata: ['user_id' => (string) $user->id, 'plan_code' => $plan->code],
        ));

        if (! $result->ok) {
            return $this->failAttempt($attempt, $result);
        }

        $attempt->update([
            'status' => 'redirected',
            'gateway_reference' => $result->reference,
        ]);

        return new CheckoutOutcome(true, $attempt->refresh(), $result);
    }

    /**
     * A trial is granted once per user, and only by a plan that offers one.
     */
    public function trialDaysFor(User $user, Plan $plan): int
    {
        if ((int) $plan->trial_days < 1) {
            return 0;
        }

        return $this->hasUsedTrial((int) $user->id) ? 0 : (int) $plan->trial_days;
    }

    public function hasUsedTrial(int $userId): bool
    {
        return Subscription::where('user_id', $userId)->whereNotNull('trial_ends_at')->exists();
    }

    /**
     * Start a trial that needs no payment yet. The gateway still owns the
     * subscription once a checkout is completed; this is the local record the
     * entitlement layer reads from during the trial.
     */
    public function startTrial(User $user, Plan $plan, string $gatewayCode): ?Subscription
    {
        $days = $this->trialDaysFor($user, $plan);
        if ($days < 1) {
            return null;
        }

        $subscription = Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'gateway' => $gatewayCode,
            'status' => 'trialing',
            'trial_ends_at' => now()->addDays($days),
            'current_period_start' => now(),
            'current_period_end' => now()->addDays($days),
        ]);
        $this->entitlements->forget((int) $user->id);

        return $subscription;
    }

    /**
     * Swap plans. When the gateway holds the subscription it must agree first -
     * we do not move the local plan on a gateway failure, because entitlements
     * would then be wider than what is being billed.
     */
    public function changePlan(Subscription $subscription, Plan $plan, ?PlanPrice $price = null): GatewayResult
    {
        if ((int) $subscription->plan_id === (int) $plan->id) {
            return GatewayResult::failure('same_plan', 'The subscription is already on that plan.');
        }
        if (in_array($subscription->status, ['canceled', 'expired'], true)) {
            return GatewayResult::failure('subscription_inactive', 'This subscription can no longer be changed.');
        }

        if ($subscription->gateway_subscription_id) {
            $gateway = $this->gateways->make($subscription->gateway);
            if (! $gateway || ! $gateway->isAvailable()) {
                return GatewayResult::failure('gateway_unavailable', "The {$subscription->gateway} gateway is not configured.");
            }
            if (! $price?->gateway_price_id) {
                return GatewayResult::failure('missing_gateway_price', 'The target plan has no gateway price for this gateway.');
            }

            $result = $gateway->changePlan($subscription->gateway_subscription_id, $price->gateway_price_id);
            if (! $result->ok) {
                return $result;
            }
        }

        $subscription->forceFill([
            'plan_id' => $plan->id,
            'plan_price_id' => $price?->id,
            'current_period_end' => $this->periodEnd($plan, $subscription->current_period_start ?? now()),
        ])->save();

        $this->entitlements->forget((int) $subscription->user_id);

        return GatewayResult::success((string) $subscription->id);
    }

    /**
     * @param  bool  $immediately  revoke access now instead of at period end
     */
    public function cancel(Subscription $subscription, bool $immediately = false): GatewayResult
    {
        if (in_array($subscription->status, ['canceled', 'expired'], true)) {
            return GatewayResult::failure('already_canceled', 'This subscription is already cancelled.');
        }

        if ($subscription->gateway_subscription_id) {
            $gateway = $this->gateways->make($subscription->gateway);
            if (! $gateway || ! $gateway->isAvailable()) {
                return GatewayResult::failure('gateway_unavailable', "The {$subscription->gateway} gateway is not configured.");
            }
            $result = $gateway->cancelSubscription($subscription->gateway_subscription_id, ! $immediately);
            if (! $result->ok) {
                return $result;
            }
        }

        if ($immediately) {
            $subscription->forceFill([
                'status' => 'canceled',
                'cancel_at_period_end' => false,
                'cancel_at' => now(),
                'canceled_at' => now(),
                'ends_at' => now(),
            ])->save();
        } else {
            $subscription->forceFill([
                'cancel_at_period_end' => true,
                'cancel_at' => $subscription->current_period_end,
                'canceled_at' => now(),
                'ends_at' => $subscription->current_period_end,
            ])->save();
        }

        $this->entitlements->forget((int) $subscription->user_id);

        return GatewayResult::success((string) $subscription->id);
    }

    /** Undo a pending cancellation while the paid period is still running. */
    public function resume(Subscription $subscription): GatewayResult
    {
        if (! $subscription->cancel_at_period_end || $subscription->status === 'canceled') {
            return GatewayResult::failure('not_resumable', 'This subscription is not scheduled for cancellation.');
        }
        if ($subscription->current_period_end && $subscription->current_period_end->isPast()) {
            return GatewayResult::failure('period_ended', 'The paid period has ended; a new checkout is required.');
        }

        if ($subscription->gateway_subscription_id) {
            $gateway = $this->gateways->make($subscription->gateway);
            if (! $gateway || ! $gateway->isAvailable()) {
                return GatewayResult::failure('gateway_unavailable', "The {$subscription->gateway} gateway is not configured.");
            }
            $result = $gateway->resumeSubscription($subscription->gateway_subscription_id);
            if (! $result->ok) {
                return $result;
            }
        }

        $subscription->forceFill([
            'cancel_at_period_end' => false,
            'cancel_at' => null,
            'canceled_at' => null,
            'ends_at' => null,
        ])->save();

        $this->entitlements->forget((int) $subscription->user_id);

        return GatewayResult::success((string) $subscription->id);
    }

    /**
     * Pull the gateway's view of a subscription over ours. Used by the nightly
     * reconciliation and after any webhook we could not process, so a missed
     * event cannot leave a user entitled to something they stopped paying for.
     */
    public function reconcile(Subscription $subscription): GatewayResult
    {
        if (! $subscription->gateway_subscription_id) {
            return GatewayResult::failure('not_gateway_managed', 'This subscription has no gateway counterpart.');
        }

        $gateway = $this->gateways->make($subscription->gateway);
        if (! $gateway || ! $gateway->isAvailable()) {
            return GatewayResult::failure('gateway_unavailable', "The {$subscription->gateway} gateway is not configured.");
        }

        $state = $gateway->fetchSubscription($subscription->gateway_subscription_id);
        if (! $state->ok) {
            return GatewayResult::failure($state->errorCode ?? 'reconcile_failed', $state->error ?? 'Gateway state unavailable.', $state->raw);
        }

        $subscription->forceFill(array_filter([
            'status' => $state->status,
            'current_period_start' => $state->currentPeriodStart,
            'current_period_end' => $state->currentPeriodEnd,
            'trial_ends_at' => $state->trialEndsAt,
            'canceled_at' => $state->canceledAt,
        ], fn ($v) => $v !== null) + [
            'cancel_at_period_end' => $state->cancelAtPeriodEnd,
        ]);

        if ($state->status === 'canceled' && ! $subscription->ends_at) {
            $subscription->ends_at = $state->currentPeriodEnd ?? now();
        }
        if ($state->cancelAtPeriodEnd && $state->currentPeriodEnd) {
            $subscription->cancel_at = $state->currentPeriodEnd;
            $subscription->ends_at = $state->currentPeriodEnd;
        }

        $subscription->save();
        $this->entitlements->forget((int) $subscription->user_id);

        return GatewayResult::success((string) $subscription->id, $state->raw);
    }

    /**
     * Create or update the local subscription a successful payment belongs to.
     * Webhooks are the only caller: this is where "paid" becomes "entitled".
     */
    public function activate(
        int $userId,
        Plan $plan,
        string $gatewayCode,
        ?string $gatewaySubscriptionId,
        ?PlanPrice $price = null,
        ?Carbon $periodStart = null,
        ?Carbon $periodEnd = null,
        ?Carbon $trialEndsAt = null,
        ?int $couponId = null,
        ?string $gatewayCustomerId = null,
    ): Subscription {
        return DB::transaction(function () use (
            $userId, $plan, $gatewayCode, $gatewaySubscriptionId, $price,
            $periodStart, $periodEnd, $trialEndsAt, $couponId, $gatewayCustomerId
        ) {
            $subscription = null;
            if ($gatewaySubscriptionId) {
                $subscription = Subscription::where('gateway', $gatewayCode)
                    ->where('gateway_subscription_id', $gatewaySubscriptionId)
                    ->lockForUpdate()
                    ->first();
            }
            $subscription ??= Subscription::where('user_id', $userId)
                ->whereIn('status', ['incomplete', 'trialing', 'past_due', 'active'])
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            $start = $periodStart ?? now();
            $end = $periodEnd ?? $this->periodEnd($plan, $start);
            $trialing = $trialEndsAt && $trialEndsAt->isFuture();

            $attributes = [
                'user_id' => $userId,
                'plan_id' => $plan->id,
                'plan_price_id' => $price?->id,
                'coupon_id' => $couponId,
                'gateway' => $gatewayCode,
                'gateway_subscription_id' => $gatewaySubscriptionId,
                'gateway_customer_id' => $gatewayCustomerId,
                'status' => $trialing ? 'trialing' : 'active',
                'trial_ends_at' => $trialEndsAt,
                'current_period_start' => $start,
                'current_period_end' => $end,
                'cancel_at' => null,
                'canceled_at' => null,
                'ends_at' => null,
                'cancel_at_period_end' => false,
            ];

            if ($subscription) {
                // Keep identifiers we already hold if the event omitted them.
                $attributes['gateway_subscription_id'] ??= $subscription->gateway_subscription_id;
                $attributes['gateway_customer_id'] ??= $subscription->gateway_customer_id;
                $attributes['coupon_id'] ??= $subscription->coupon_id;
                $subscription->forceFill($attributes)->save();
            } else {
                $subscription = Subscription::create($attributes);
            }

            $this->entitlements->forget($userId);

            return $subscription;
        });
    }

    /** Where the next period ends for a plan, or null for lifetime access. */
    public function periodEnd(Plan $plan, ?Carbon $from = null): ?Carbon
    {
        $from = $from ? $from->copy() : Carbon::now();
        $count = max(1, (int) $plan->interval_count);

        return match ($plan->interval) {
            'monthly' => $from->addMonthsNoOverflow($count),
            'quarterly' => $from->addMonthsNoOverflow(3 * $count),
            'annual' => $from->addYearsNoOverflow($count),
            'lifetime' => null,
            default => $from->addMonthsNoOverflow($count),
        };
    }

    /** Mark subscriptions whose paid period has run out, so access stops on time. */
    public function expireLapsed(): int
    {
        return Subscription::whereIn('status', ['active', 'trialing', 'past_due'])
            ->whereNotNull('ends_at')
            ->where('ends_at', '<=', now())
            ->update(['status' => 'expired']);
    }

    public function gatewayCustomerId(int $userId, string $gatewayCode): ?string
    {
        return Subscription::where('user_id', $userId)
            ->where('gateway', $gatewayCode)
            ->whereNotNull('gateway_customer_id')
            ->orderByDesc('id')
            ->value('gateway_customer_id');
    }

    public function defaultGateway(): string
    {
        return BillingConfig::defaultGateway();
    }

    private function failAttempt(PaymentAttempt $attempt, CheckoutResult $result): CheckoutOutcome
    {
        $attempt->update([
            'status' => 'failed',
            'failure_reason' => trim(($result->errorCode ?? 'error').': '.($result->error ?? 'Checkout failed.')),
        ]);

        return new CheckoutOutcome(false, $attempt->refresh(), $result);
    }

    private function idempotencyKey(): string
    {
        // Also serves as the merchant order id at PayTR, which only accepts
        // alphanumerics, so no dashes here.
        return 'zbn'.now()->format('YmdHis').Str::lower(Str::random(12));
    }
}
