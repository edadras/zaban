<?php

namespace Tests\Feature\Billing;

use App\Billing\BillingConfig;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Invoice;
use App\Models\PaymentAttempt;
use App\Models\PaymentWebhook;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;

class WebhookHandlingTest extends BillingTestCase
{
    private string $secret = 'whsec_test_secret';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'billing.gateways.stripe.secret_key' => 'sk_test_key',
            'billing.gateways.stripe.webhook_secret' => $this->secret,
        ]);
    }

    private function deliver(array $event, ?string $signatureHeader = null): TestResponse
    {
        $payload = (string) json_encode($event);
        $timestamp = time();
        $header = $signatureHeader ?? 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$payload, $this->secret);

        return $this->call(
            'POST',
            '/api/v1/billing/webhooks/stripe',
            [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $header],
            $payload,
        );
    }

    /** @return array{0: User, 1: Plan, 2: PaymentAttempt} */
    private function pendingCheckout(?Coupon $coupon = null): array
    {
        $plan = $this->plan(['ai_messages' => [true, 200, 'day']], [], 24900);
        $user = $this->user();

        $attempt = PaymentAttempt::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'plan_price_id' => $plan->prices()->value('id'),
            'coupon_id' => $coupon?->id,
            'gateway' => 'stripe',
            'idempotency_key' => 'zbn'.Str::lower(Str::random(16)),
            'status' => 'redirected',
            'amount' => 24900,
            'currency' => 'TRY',
            'metadata' => ['plan_code' => $plan->code, 'discount' => $coupon ? 2490 : 0],
        ]);

        return [$user, $plan, $attempt];
    }

    private function invoicePaidEvent(PaymentAttempt $attempt, User $user, string $eventId, string $subscriptionId): array
    {
        return [
            'id' => $eventId,
            'object' => 'event',
            'type' => 'invoice.paid',
            'data' => ['object' => [
                'id' => 'in_'.Str::random(12),
                'object' => 'invoice',
                'customer' => 'cus_'.Str::random(10),
                'subscription' => $subscriptionId,
                'payment_intent' => 'pi_'.Str::random(12),
                'amount_paid' => 24900,
                'currency' => 'try',
                'metadata' => ['idempotency_key' => $attempt->idempotency_key, 'user_id' => (string) $user->id],
                'lines' => ['data' => [['period' => [
                    'start' => now()->getTimestamp(),
                    'end' => now()->addMonth()->getTimestamp(),
                ]]]],
            ]],
        ];
    }

    #[Test]
    public function an_unsigned_delivery_is_rejected_and_recorded(): void
    {
        [$user, , $attempt] = $this->pendingCheckout();
        $event = $this->invoicePaidEvent($attempt, $user, 'evt_'.Str::random(10), 'sub_'.Str::random(10));

        $this->deliver($event, 't='.time().',v1=deadbeef')
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'invalid_signature');

        $webhook = PaymentWebhook::where('event_id', $event['id'])->firstOrFail();
        $this->assertFalse((bool) $webhook->signature_verified);
        $this->assertSame('failed', $webhook->status);
        $this->assertSame('Stripe signature mismatch.', $webhook->error);

        // Nothing may be applied from an unverified event.
        $this->assertSame(0, Subscription::where('user_id', $user->id)->count());
        $this->assertSame(0, SubscriptionTransaction::where('user_id', $user->id)->count());
    }

    #[Test]
    public function a_missing_signature_header_is_rejected(): void
    {
        [$user, , $attempt] = $this->pendingCheckout();
        $event = $this->invoicePaidEvent($attempt, $user, 'evt_'.Str::random(10), 'sub_'.Str::random(10));
        $payload = (string) json_encode($event);

        $this->call('POST', '/api/v1/billing/webhooks/stripe', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], $payload)
            ->assertStatus(400);

        $this->assertSame('Missing Stripe-Signature header.', PaymentWebhook::where('event_id', $event['id'])->value('error'));
    }

    #[Test]
    public function a_replayed_timestamp_outside_tolerance_is_rejected(): void
    {
        [$user, , $attempt] = $this->pendingCheckout();
        $event = $this->invoicePaidEvent($attempt, $user, 'evt_'.Str::random(10), 'sub_'.Str::random(10));
        $payload = (string) json_encode($event);
        $stale = time() - 3600;

        $this->deliver($event, 't='.$stale.',v1='.hash_hmac('sha256', $stale.'.'.$payload, $this->secret))
            ->assertStatus(400);

        $this->assertSame('Stripe signature timestamp outside tolerance.', PaymentWebhook::where('event_id', $event['id'])->value('error'));
    }

    #[Test]
    public function an_unconfigured_webhook_secret_rejects_every_delivery(): void
    {
        config(['billing.gateways.stripe.webhook_secret' => null]);
        // The manager memoises adapters, so a fresh container is what a real
        // request with this config would get.
        $this->refreshApplication();
        $this->registerRoutes();
        config(['billing' => BillingConfig::defaults()]);

        [$user, , $attempt] = $this->pendingCheckout();
        $event = $this->invoicePaidEvent($attempt, $user, 'evt_'.Str::random(10), 'sub_'.Str::random(10));

        $this->deliver($event)->assertStatus(400)->assertJsonPath('error.code', 'invalid_signature');
        $this->assertSame('Stripe webhook secret is not configured.', PaymentWebhook::where('event_id', $event['id'])->value('error'));
    }

    #[Test]
    public function a_paid_invoice_activates_the_subscription_and_issues_one_invoice(): void
    {
        [$user, $plan, $attempt] = $this->pendingCheckout();
        $subscriptionId = 'sub_'.Str::random(10);
        $event = $this->invoicePaidEvent($attempt, $user, 'evt_'.Str::random(10), $subscriptionId);

        $this->deliver($event)->assertOk()->assertJsonPath('data.status', 'processed');

        $subscription = Subscription::where('user_id', $user->id)->firstOrFail();
        $this->assertSame('active', $subscription->status);
        $this->assertSame($plan->id, $subscription->plan_id);
        $this->assertSame($subscriptionId, $subscription->gateway_subscription_id);
        $this->assertNotNull($subscription->current_period_end);
        $this->assertTrue($subscription->current_period_end->isFuture());

        $transaction = SubscriptionTransaction::where('user_id', $user->id)->firstOrFail();
        $this->assertSame('succeeded', $transaction->status);
        $this->assertSame(24900, (int) $transaction->amount);

        $invoice = Invoice::where('user_id', $user->id)->firstOrFail();
        $this->assertSame('paid', $invoice->status);
        $this->assertSame(24900, (int) $invoice->total);
        $this->assertStringStartsWith('ZBN-'.now()->format('Y').'-', $invoice->number);

        $this->assertSame('succeeded', $attempt->refresh()->status);
        $this->assertSame('processed', PaymentWebhook::where('event_id', $event['id'])->value('status'));
    }

    #[Test]
    public function a_redelivered_event_is_deduped_and_changes_nothing(): void
    {
        [$user, , $attempt] = $this->pendingCheckout();
        $event = $this->invoicePaidEvent($attempt, $user, 'evt_'.Str::random(10), 'sub_'.Str::random(10));

        $this->deliver($event)->assertOk()->assertJsonPath('data.status', 'processed');
        $this->deliver($event)->assertOk()->assertJsonPath('data.status', 'duplicate');
        $this->deliver($event)->assertOk()->assertJsonPath('data.status', 'duplicate');

        $this->assertSame(1, PaymentWebhook::where('event_id', $event['id'])->count());
        $this->assertSame(1, Invoice::where('user_id', $user->id)->count());
        $this->assertSame(1, SubscriptionTransaction::where('user_id', $user->id)->count());
        $this->assertSame(1, Subscription::where('user_id', $user->id)->count());
    }

    #[Test]
    public function a_second_charge_on_the_same_subscription_extends_the_period_and_adds_an_invoice(): void
    {
        [$user, , $attempt] = $this->pendingCheckout();
        $subscriptionId = 'sub_'.Str::random(10);

        $this->deliver($this->invoicePaidEvent($attempt, $user, 'evt_'.Str::random(10), $subscriptionId))->assertOk();
        $renewal = $this->invoicePaidEvent($attempt, $user, 'evt_'.Str::random(10), $subscriptionId);
        $renewal['data']['object']['lines']['data'][0]['period'] = [
            'start' => now()->addMonth()->getTimestamp(),
            'end' => now()->addMonths(2)->getTimestamp(),
        ];

        $this->deliver($renewal)->assertOk()->assertJsonPath('data.status', 'processed');

        $this->assertSame(1, Subscription::where('user_id', $user->id)->count());
        $this->assertSame(2, Invoice::where('user_id', $user->id)->count());
        $this->assertTrue(Subscription::where('user_id', $user->id)->value('current_period_end') > now()->addMonth()->toDateTimeString());
    }

    #[Test]
    public function the_coupon_is_redeemed_only_when_the_payment_succeeds(): void
    {
        $coupon = Coupon::create([
            'code' => 'WH'.Str::upper(Str::random(8)),
            'discount_type' => 'percent',
            'discount_value' => 10,
            'is_active' => true,
        ]);
        [$user, , $attempt] = $this->pendingCheckout($coupon);

        $this->assertSame(0, CouponRedemption::where('coupon_id', $coupon->id)->count());

        $this->deliver($this->invoicePaidEvent($attempt, $user, 'evt_'.Str::random(10), 'sub_'.Str::random(10)))->assertOk();

        $this->assertSame(1, CouponRedemption::where('coupon_id', $coupon->id)->where('user_id', $user->id)->count());
        $this->assertSame(1, (int) $coupon->refresh()->redemption_count);
        $this->assertSame(2490, (int) Invoice::where('user_id', $user->id)->value('discount_total'));
    }

    #[Test]
    public function a_failed_payment_moves_the_subscription_to_past_due_without_ending_the_period(): void
    {
        [$user, $plan, $attempt] = $this->pendingCheckout();
        $subscriptionId = 'sub_'.Str::random(10);
        $this->deliver($this->invoicePaidEvent($attempt, $user, 'evt_'.Str::random(10), $subscriptionId))->assertOk();
        $periodEnd = Subscription::where('user_id', $user->id)->value('current_period_end');

        $this->deliver([
            'id' => 'evt_'.Str::random(10),
            'type' => 'invoice.payment_failed',
            'data' => ['object' => [
                'id' => 'in_'.Str::random(12),
                'object' => 'invoice',
                'subscription' => $subscriptionId,
                'payment_intent' => 'pi_'.Str::random(12),
                'amount_paid' => 0,
                'amount_due' => 24900,
                'currency' => 'try',
                'metadata' => ['idempotency_key' => $attempt->idempotency_key],
                'last_payment_error' => ['message' => 'Your card was declined.'],
            ]],
        ])->assertOk()->assertJsonPath('data.status', 'processed');

        $subscription = Subscription::where('user_id', $user->id)->firstOrFail();
        $this->assertSame('past_due', $subscription->status);
        $this->assertSame($periodEnd->toDateTimeString(), $subscription->current_period_end->toDateTimeString());
        $this->assertSame('failed', SubscriptionTransaction::where('user_id', $user->id)->where('status', 'failed')->value('status'));
        $this->assertSame('Your card was declined.', SubscriptionTransaction::where('user_id', $user->id)->where('status', 'failed')->value('failure_reason'));
    }

    #[Test]
    public function a_deleted_subscription_event_ends_access(): void
    {
        [$user, , $attempt] = $this->pendingCheckout();
        $subscriptionId = 'sub_'.Str::random(10);
        $this->deliver($this->invoicePaidEvent($attempt, $user, 'evt_'.Str::random(10), $subscriptionId))->assertOk();

        $this->deliver([
            'id' => 'evt_'.Str::random(10),
            'type' => 'customer.subscription.deleted',
            'data' => ['object' => [
                'id' => $subscriptionId,
                'object' => 'subscription',
                'status' => 'canceled',
                'current_period_end' => now()->subMinute()->getTimestamp(),
                'cancel_at_period_end' => false,
            ]],
        ])->assertOk()->assertJsonPath('data.status', 'processed');

        $subscription = Subscription::where('user_id', $user->id)->firstOrFail();
        $this->assertSame('canceled', $subscription->status);
        $this->assertNotNull($subscription->ends_at);
    }

    #[Test]
    public function an_unhandled_event_type_is_recorded_as_ignored(): void
    {
        $event = ['id' => 'evt_'.Str::random(10), 'type' => 'customer.discount.created', 'data' => ['object' => []]];

        $this->deliver($event)->assertOk()->assertJsonPath('data.status', 'ignored');

        $webhook = PaymentWebhook::where('event_id', $event['id'])->firstOrFail();
        $this->assertSame('ignored', $webhook->status);
        $this->assertTrue((bool) $webhook->signature_verified);
    }

    #[Test]
    public function an_unknown_gateway_is_a_404(): void
    {
        $this->postJson('/api/v1/billing/webhooks/braintree', ['id' => 'evt_1'])
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'unknown_gateway');
    }
}
