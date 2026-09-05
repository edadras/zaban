<?php

namespace App\Services\Billing;

use App\Billing\Support\WebhookEvent;
use App\Models\Coupon;
use App\Models\PaymentAttempt;
use App\Models\PaymentWebhook;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Verifies, deduplicates and applies gateway webhooks.
 *
 * Three rules hold for every gateway:
 *   1. an unverified signature is never processed, only recorded;
 *   2. (gateway, event_id) is unique, so a redelivery is a no-op; and
 *   3. handlers are written to be re-runnable, because gateways retry for days.
 */
class WebhookService
{
    public function __construct(
        private GatewayManager $gateways,
        private SubscriptionService $subscriptions,
        private InvoiceService $invoices,
        private CouponService $coupons,
    ) {}

    /**
     * @param  array<string, string|array>  $headers
     * @return array{status: string, webhook: ?PaymentWebhook, message: ?string}
     */
    public function handle(string $gatewayCode, string $payload, array $headers): array
    {
        $gateway = $this->gateways->make($gatewayCode);
        if (! $gateway) {
            return ['status' => 'unknown_gateway', 'webhook' => null, 'message' => "Unknown payment gateway [{$gatewayCode}]."];
        }

        $event = $gateway->parseWebhook($payload, $headers);

        if (! $event->verified) {
            $webhook = $this->record($event, 'failed', $event->error);

            return ['status' => 'rejected', 'webhook' => $webhook, 'message' => $event->error];
        }

        $webhook = $this->record($event, 'received');

        if ($webhook->status === 'processed') {
            return ['status' => 'duplicate', 'webhook' => $webhook, 'message' => 'Event already processed.'];
        }

        $webhook->increment('attempts');

        try {
            $outcome = $this->apply($event);
            $webhook->forceFill([
                'status' => $outcome['handled'] ? 'processed' : 'ignored',
                'error' => $outcome['handled'] ? null : $outcome['reason'],
                'processed_at' => now(),
            ])->save();

            return ['status' => $outcome['handled'] ? 'processed' : 'ignored', 'webhook' => $webhook, 'message' => $outcome['reason']];
        } catch (Throwable $e) {
            Log::error('Billing webhook failed', [
                'gateway' => $event->gateway,
                'event_id' => $event->eventId,
                'type' => $event->type,
                'exception' => $e->getMessage(),
            ]);
            $webhook->forceFill(['status' => 'failed', 'error' => $e->getMessage()])->save();

            return ['status' => 'failed', 'webhook' => $webhook, 'message' => $e->getMessage()];
        }
    }

    /**
     * Dedupe lives in the unique (gateway, event_id) index rather than in a
     * read-then-write, so two concurrent deliveries of one event cannot both
     * pass the check.
     */
    private function record(WebhookEvent $event, string $status, ?string $error = null): PaymentWebhook
    {
        $attributes = [
            'gateway' => $event->gateway,
            'event_type' => mb_substr($event->type, 0, 96),
            'signature_verified' => $event->verified,
            'status' => $status,
            'payload' => $event->payload,
            'error' => $error,
        ];

        if ($event->eventId === null) {
            return PaymentWebhook::create($attributes + ['event_id' => null]);
        }

        try {
            return PaymentWebhook::create($attributes + ['event_id' => $event->eventId]);
        } catch (QueryException $e) {
            $duplicate = (int) ($e->errorInfo[1] ?? 0) === 1062 || str_contains($e->getMessage(), 'Duplicate entry');
            if (! $duplicate) {
                throw $e;
            }

            return PaymentWebhook::where('gateway', $event->gateway)->where('event_id', $event->eventId)->firstOrFail();
        }
    }

    /** @return array{handled: bool, reason: ?string} */
    private function apply(WebhookEvent $event): array
    {
        $action = $this->canonicalAction($event);
        if ($action === null) {
            return ['handled' => false, 'reason' => "No handler for event type [{$event->type}]."];
        }

        $data = $this->extract($event);

        return match ($action) {
            'payment_succeeded' => $this->onPaymentSucceeded($event, $data),
            'payment_failed' => $this->onPaymentFailed($event, $data),
            'payment_refunded' => $this->onPaymentRefunded($event, $data),
            'subscription_updated' => $this->onSubscriptionUpdated($event, $data),
            'subscription_canceled' => $this->onSubscriptionCanceled($event, $data),
            default => ['handled' => false, 'reason' => "No handler for event type [{$event->type}]."],
        };
    }

    /** Vendor event names collapsed into the five things we actually act on. */
    public function canonicalAction(WebhookEvent $event): ?string
    {
        $type = $event->type;

        return match ($event->gateway) {
            'stripe' => match ($type) {
                'checkout.session.completed', 'invoice.paid', 'invoice.payment_succeeded' => 'payment_succeeded',
                'invoice.payment_failed' => 'payment_failed',
                'charge.refunded' => 'payment_refunded',
                'customer.subscription.created', 'customer.subscription.updated' => 'subscription_updated',
                'customer.subscription.deleted' => 'subscription_canceled',
                default => null,
            },
            'iyzico' => match (strtoupper($type)) {
                'SUBSCRIPTION_ORDER_SUCCESS', 'SUBSCRIPTION_ACTIVATED' => 'payment_succeeded',
                'SUBSCRIPTION_ORDER_FAILED', 'SUBSCRIPTION_UNPAID' => 'payment_failed',
                'SUBSCRIPTION_CANCELED', 'SUBSCRIPTION_EXPIRED' => 'subscription_canceled',
                'SUBSCRIPTION_UPGRADED', 'SUBSCRIPTION_RENEWED' => 'subscription_updated',
                'REFUND' => 'payment_refunded',
                default => null,
            },
            'paytr' => match ($type) {
                'payment.success' => 'payment_succeeded',
                'payment.failed' => 'payment_failed',
                default => null,
            },
            default => null,
        };
    }

    /**
     * Flatten a vendor payload into the fields every handler needs.
     *
     * @return array<string, mixed>
     */
    private function extract(WebhookEvent $event): array
    {
        $p = $event->payload;

        return match ($event->gateway) {
            'stripe' => $this->extractStripe($p),
            'iyzico' => $this->extractIyzico($p),
            'paytr' => $this->extractPayTR($p),
            default => [],
        };
    }

    private function extractStripe(array $payload): array
    {
        $object = $payload['data']['object'] ?? [];
        $line = $object['lines']['data'][0] ?? [];
        $metadata = $object['metadata'] ?? ($object['subscription_details']['metadata'] ?? []);

        $subscriptionId = $object['subscription'] ?? null;
        if (! is_string($subscriptionId) && ($object['object'] ?? null) === 'subscription') {
            // customer.subscription.* events carry the subscription as the object itself.
            $subscriptionId = $object['id'] ?? null;
        }

        return [
            'gateway_subscription_id' => is_string($subscriptionId) ? $subscriptionId : null,
            'gateway_customer_id' => $object['customer'] ?? null,
            'gateway_transaction_id' => $object['payment_intent'] ?? $object['charge'] ?? $object['id'] ?? null,
            'amount' => (int) ($object['amount_paid'] ?? $object['amount_total'] ?? $object['amount'] ?? 0),
            'refunded_amount' => (int) ($object['amount_refunded'] ?? 0),
            'currency' => strtoupper((string) ($object['currency'] ?? 'USD')),
            'idempotency_key' => $metadata['idempotency_key'] ?? ($object['client_reference_id'] ?? null),
            'user_id' => isset($metadata['user_id']) ? (int) $metadata['user_id'] : null,
            'period_start' => $this->timestamp($object['current_period_start'] ?? $line['period']['start'] ?? null),
            'period_end' => $this->timestamp($object['current_period_end'] ?? $line['period']['end'] ?? null),
            'trial_ends_at' => $this->timestamp($object['trial_end'] ?? null),
            'cancel_at_period_end' => (bool) ($object['cancel_at_period_end'] ?? false),
            'status' => $object['status'] ?? null,
            'failure_reason' => $object['last_payment_error']['message'] ?? null,
        ];
    }

    private function extractIyzico(array $payload): array
    {
        $data = $payload['data'] ?? $payload;
        $price = $data['price'] ?? $payload['price'] ?? 0;

        return [
            'gateway_subscription_id' => $data['subscriptionReferenceCode'] ?? $payload['subscriptionReferenceCode'] ?? null,
            'gateway_customer_id' => $data['customerReferenceCode'] ?? $payload['customerReferenceCode'] ?? null,
            'gateway_transaction_id' => (string) ($data['paymentId'] ?? $payload['iyziPaymentId'] ?? $payload['iyziReferenceCode'] ?? ''),
            // iyzico prices are decimal major units.
            'amount' => (int) round(((float) $price) * 100),
            'refunded_amount' => 0,
            'currency' => strtoupper((string) ($data['currencyCode'] ?? $payload['currency'] ?? 'TRY')),
            'idempotency_key' => $data['conversationId'] ?? $payload['conversationId'] ?? null,
            'user_id' => null,
            'period_start' => $this->timestamp($data['startPeriodDate'] ?? null),
            'period_end' => $this->timestamp($data['endPeriodDate'] ?? null),
            'trial_ends_at' => $this->timestamp($data['trialEndDate'] ?? null),
            'cancel_at_period_end' => false,
            'status' => $data['subscriptionStatus'] ?? $payload['status'] ?? null,
            'failure_reason' => $payload['errorMessage'] ?? null,
        ];
    }

    private function extractPayTR(array $payload): array
    {
        return [
            'gateway_subscription_id' => $payload['merchant_oid'] ?? null,
            'gateway_customer_id' => null,
            'gateway_transaction_id' => $payload['merchant_oid'] ?? null,
            'amount' => (int) ($payload['total_amount'] ?? $payload['payment_amount'] ?? 0),
            'refunded_amount' => 0,
            'currency' => strtoupper((string) ($payload['currency'] ?? 'TRY')),
            'idempotency_key' => $payload['merchant_oid'] ?? null,
            'user_id' => null,
            'period_start' => null,
            'period_end' => null,
            'trial_ends_at' => null,
            'cancel_at_period_end' => false,
            'status' => $payload['status'] ?? null,
            'failure_reason' => $payload['failed_reason_msg'] ?? null,
        ];
    }

    private function onPaymentSucceeded(WebhookEvent $event, array $data): array
    {
        $attempt = $this->attemptFor($data);
        $subscription = $this->subscriptionFor($event->gateway, $data, $attempt);
        $plan = $this->planFor($attempt, $subscription);

        if (! $plan) {
            return ['handled' => false, 'reason' => 'Payment could not be matched to a plan.'];
        }

        $userId = (int) ($attempt?->user_id ?? $subscription?->user_id ?? $data['user_id'] ?? 0);
        if ($userId < 1) {
            return ['handled' => false, 'reason' => 'Payment could not be matched to a user.'];
        }

        $price = $attempt?->plan_price_id ? PlanPrice::find($attempt->plan_price_id) : null;
        $periodStart = $data['period_start'] ?? now();
        $periodEnd = $data['period_end'] ?? $this->subscriptions->periodEnd($plan, $periodStart);

        $subscription = $this->subscriptions->activate(
            userId: $userId,
            plan: $plan,
            gatewayCode: $event->gateway,
            gatewaySubscriptionId: $data['gateway_subscription_id'] ?? $subscription?->gateway_subscription_id,
            price: $price,
            periodStart: $periodStart,
            periodEnd: $periodEnd,
            trialEndsAt: $data['trial_ends_at'] ?? null,
            couponId: $attempt?->coupon_id,
            gatewayCustomerId: $data['gateway_customer_id'] ?? null,
        );

        $amount = (int) ($data['amount'] ?: ($attempt?->amount ?? 0));
        $transaction = $this->upsertTransaction($event->gateway, $data, [
            'subscription_id' => $subscription->id,
            'user_id' => $userId,
            'type' => 'charge',
            'status' => 'succeeded',
            'amount' => $amount,
            'currency' => $data['currency'] ?: ($attempt?->currency ?? 'TRY'),
            'processed_at' => now(),
        ], $event->payload);

        if ($attempt && $attempt->status !== 'succeeded') {
            $attempt->update(['status' => 'succeeded']);
        }

        if ($attempt?->coupon_id) {
            $coupon = Coupon::find($attempt->coupon_id);
            if ($coupon && ! $this->coupons->alreadyRedeemed((int) $coupon->id, $userId)) {
                $this->coupons->redeem($coupon, $userId, (int) $subscription->id);
            }
        }

        $discount = (int) ($attempt?->metadata['discount'] ?? 0);
        $this->invoices->issueForTransaction($transaction, $subscription, $discount);

        return ['handled' => true, 'reason' => null];
    }

    private function onPaymentFailed(WebhookEvent $event, array $data): array
    {
        $attempt = $this->attemptFor($data);
        $subscription = $this->subscriptionFor($event->gateway, $data, $attempt);
        $userId = (int) ($attempt?->user_id ?? $subscription?->user_id ?? 0);
        if ($userId < 1) {
            return ['handled' => false, 'reason' => 'Failed payment could not be matched to a user.'];
        }

        $this->upsertTransaction($event->gateway, $data, [
            'subscription_id' => $subscription?->id,
            'user_id' => $userId,
            'type' => 'charge',
            'status' => 'failed',
            'amount' => (int) ($data['amount'] ?: ($attempt?->amount ?? 0)),
            'currency' => $data['currency'] ?: ($attempt?->currency ?? 'TRY'),
            'failure_reason' => $data['failure_reason'],
            'processed_at' => now(),
        ], $event->payload);

        $attempt?->update(['status' => 'failed', 'failure_reason' => $data['failure_reason'] ?? 'Payment failed at the gateway.']);

        if ($subscription && in_array($subscription->status, ['active', 'trialing'], true)) {
            // Keep the paid period intact: the gateway retries, and cancelling
            // here would revoke access the user has already paid for.
            $subscription->forceFill(['status' => 'past_due'])->save();
        }

        return ['handled' => true, 'reason' => null];
    }

    private function onPaymentRefunded(WebhookEvent $event, array $data): array
    {
        $transaction = SubscriptionTransaction::where('gateway', $event->gateway)
            ->where('gateway_transaction_id', $data['gateway_transaction_id'])
            ->first();

        if (! $transaction) {
            return ['handled' => false, 'reason' => 'Refund does not match a recorded charge.'];
        }

        $refunded = (int) ($data['refunded_amount'] ?: $data['amount']);
        $transaction->forceFill([
            'refunded_amount' => min((int) $transaction->amount, $refunded),
            'status' => $refunded >= (int) $transaction->amount ? 'refunded' : $transaction->status,
        ])->save();

        return ['handled' => true, 'reason' => null];
    }

    private function onSubscriptionUpdated(WebhookEvent $event, array $data): array
    {
        $subscription = $this->subscriptionFor($event->gateway, $data, $this->attemptFor($data));
        if (! $subscription) {
            return ['handled' => false, 'reason' => 'Subscription update does not match a local subscription.'];
        }

        $status = $this->mapStatus($event->gateway, (string) ($data['status'] ?? ''));
        $subscription->forceFill(array_filter([
            'status' => $status,
            'current_period_start' => $data['period_start'],
            'current_period_end' => $data['period_end'],
            'trial_ends_at' => $data['trial_ends_at'],
            'gateway_customer_id' => $data['gateway_customer_id'],
        ], fn ($v) => $v !== null) + ['cancel_at_period_end' => (bool) $data['cancel_at_period_end']]);

        if ($data['cancel_at_period_end'] && $subscription->current_period_end) {
            $subscription->cancel_at = $subscription->current_period_end;
            $subscription->ends_at = $subscription->current_period_end;
        }
        $subscription->save();

        return ['handled' => true, 'reason' => null];
    }

    private function onSubscriptionCanceled(WebhookEvent $event, array $data): array
    {
        $subscription = $this->subscriptionFor($event->gateway, $data, $this->attemptFor($data));
        if (! $subscription) {
            return ['handled' => false, 'reason' => 'Cancellation does not match a local subscription.'];
        }

        $endsAt = $data['period_end'] ?? now();
        $subscription->forceFill([
            'status' => $endsAt->isFuture() ? $subscription->status : 'canceled',
            'cancel_at_period_end' => $endsAt->isFuture(),
            'cancel_at' => $endsAt,
            'canceled_at' => now(),
            'ends_at' => $endsAt,
        ])->save();

        return ['handled' => true, 'reason' => null];
    }

    private function upsertTransaction(string $gateway, array $data, array $attributes, array $payload): SubscriptionTransaction
    {
        $reference = $data['gateway_transaction_id'] ?: null;

        return DB::transaction(function () use ($gateway, $reference, $attributes, $payload) {
            $existing = $reference
                ? SubscriptionTransaction::where('gateway', $gateway)->where('gateway_transaction_id', $reference)->lockForUpdate()->first()
                : null;

            if ($existing) {
                $existing->forceFill($attributes + ['gateway_payload' => $payload])->save();

                return $existing;
            }

            return SubscriptionTransaction::create($attributes + [
                'gateway' => $gateway,
                'gateway_transaction_id' => $reference,
                'gateway_payload' => $payload,
            ]);
        });
    }

    private function attemptFor(array $data): ?PaymentAttempt
    {
        $key = $data['idempotency_key'] ?? null;

        return $key ? PaymentAttempt::where('idempotency_key', $key)->first() : null;
    }

    private function subscriptionFor(string $gateway, array $data, ?PaymentAttempt $attempt): ?Subscription
    {
        if (! empty($data['gateway_subscription_id'])) {
            $found = Subscription::where('gateway', $gateway)
                ->where('gateway_subscription_id', $data['gateway_subscription_id'])
                ->first();
            if ($found) {
                return $found;
            }
        }

        $userId = $attempt?->user_id ?? $data['user_id'] ?? null;

        return $userId
            ? Subscription::where('user_id', $userId)->orderByDesc('id')->first()
            : null;
    }

    private function planFor(?PaymentAttempt $attempt, ?Subscription $subscription): ?Plan
    {
        if ($attempt?->plan_id) {
            return Plan::find($attempt->plan_id);
        }

        return $subscription?->plan;
    }

    private function mapStatus(string $gateway, string $status): ?string
    {
        if ($status === '') {
            return null;
        }

        return match ($gateway) {
            'stripe' => match ($status) {
                'trialing' => 'trialing',
                'active' => 'active',
                'past_due', 'unpaid' => 'past_due',
                'paused' => 'paused',
                'canceled' => 'canceled',
                'incomplete_expired' => 'expired',
                default => 'incomplete',
            },
            'iyzico' => match (strtoupper($status)) {
                'ACTIVE' => 'active',
                'UNPAID' => 'past_due',
                'CANCELED', 'UPGRADED' => 'canceled',
                'EXPIRED' => 'expired',
                default => 'incomplete',
            },
            default => null,
        };
    }

    private function timestamp(mixed $value): ?Carbon
    {
        if ($value === null || $value === '' || $value === 0) {
            return null;
        }
        if (is_int($value) || ctype_digit((string) $value)) {
            $number = (int) $value;

            // Epoch milliseconds appear in iyzico payloads, seconds in Stripe's.
            return $number > 100000000000 ? Carbon::createFromTimestampMs($number) : Carbon::createFromTimestamp($number);
        }

        return rescue(fn () => Carbon::parse((string) $value), null, false);
    }
}
