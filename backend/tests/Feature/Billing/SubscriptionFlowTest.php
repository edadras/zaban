<?php

namespace Tests\Feature\Billing;

use App\Models\Invoice;
use App\Models\PaymentAttempt;
use App\Models\Subscription;
use App\Services\Billing\CouponService;
use App\Services\Billing\EntitlementService;
use App\Services\Billing\GatewayManager;
use App\Services\Billing\SubscriptionService;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;

class SubscriptionFlowTest extends BillingTestCase
{
    private function subscriptions(): SubscriptionService
    {
        return new SubscriptionService(new GatewayManager, new CouponService, new EntitlementService);
    }

    private function activeSubscription(int $userId, int $planId, array $attributes = []): Subscription
    {
        return Subscription::create($attributes + [
            'user_id' => $userId,
            'plan_id' => $planId,
            'gateway' => 'stripe',
            'status' => 'active',
            'current_period_start' => now()->subDays(3),
            'current_period_end' => now()->addDays(27),
        ]);
    }

    #[Test]
    public function the_plan_catalogue_is_public(): void
    {
        $plan = $this->plan(['ai_messages' => [true, 200, 'day']], ['name' => 'Visible Plan']);

        $response = $this->getJson('/api/v1/billing/plans')->assertOk();

        $codes = collect($response->json('data'))->pluck('code');
        $this->assertTrue($codes->contains($plan->code));

        $row = collect($response->json('data'))->firstWhere('code', $plan->code);
        $this->assertSame('Visible Plan', $row['name']);
        $this->assertSame(24900, $row['prices'][0]['amount']);
        $this->assertSame('ai_messages', $row['entitlements'][0]['feature']);
    }

    #[Test]
    public function a_private_plan_is_hidden_from_the_catalogue(): void
    {
        $plan = $this->plan([], ['is_public' => false]);

        $response = $this->getJson('/api/v1/billing/plans')->assertOk();
        $this->assertFalse(collect($response->json('data'))->pluck('code')->contains($plan->code));
    }

    #[Test]
    public function the_current_subscription_endpoint_reports_the_free_tier_and_its_entitlements(): void
    {
        $free = $this->plan(['ai_messages' => [true, 15, 'day'], 'premium_tutor' => [false, 0, 'month']]);
        $this->useAsFreePlan($free);
        $user = $this->user();

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/billing/subscription')
            ->assertOk()
            ->assertJsonPath('data.subscription', null)
            ->assertJsonPath('data.plan', $free->code)
            ->assertJsonPath('data.entitlements.ai_messages.limit', 15)
            ->assertJsonPath('data.entitlements.ai_messages.remaining', 15)
            ->assertJsonPath('data.entitlements.premium_tutor.enabled', false);
    }

    #[Test]
    public function the_current_subscription_endpoint_reports_an_active_plan(): void
    {
        $plan = $this->plan(['ai_messages' => [true, 200, 'day']]);
        $user = $this->user();
        $this->activeSubscription($user->id, $plan->id);

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/billing/subscription')
            ->assertOk()
            ->assertJsonPath('data.subscription.status', 'active')
            ->assertJsonPath('data.subscription.plan.code', $plan->code)
            ->assertJsonPath('data.entitlements.ai_messages.remaining', 200);
    }

    #[Test]
    public function the_subscription_endpoint_requires_authentication(): void
    {
        $this->getJson('/api/v1/billing/subscription')->assertStatus(401);
    }

    #[Test]
    public function an_unconfigured_gateway_reports_itself_unavailable_instead_of_faking_a_checkout(): void
    {
        $manager = new GatewayManager;

        foreach (['stripe', 'iyzico', 'paytr'] as $code) {
            $gateway = $manager->make($code);
            $this->assertNotNull($gateway, "gateway [{$code}] should be resolvable");
            $this->assertFalse($gateway->isAvailable());
        }

        $this->assertSame([], $manager->availableCodes());
        $this->assertNull($manager->make('braintree'));
    }

    #[Test]
    public function a_checkout_against_an_unconfigured_gateway_fails_cleanly_and_records_the_attempt(): void
    {
        $plan = $this->plan(['ai_messages' => [true, 200, 'day']]);
        $user = $this->user();

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/billing/checkout', [
            'plan_code' => $plan->code,
            'gateway' => 'stripe',
            'currency' => 'TRY',
            'success_url' => 'https://zaban.app/billing/done',
            'cancel_url' => 'https://zaban.app/billing/cancel',
        ]);

        $response->assertStatus(502)->assertJsonPath('error.code', 'gateway_unavailable');

        $attempt = PaymentAttempt::where('user_id', $user->id)->firstOrFail();
        $this->assertSame('failed', $attempt->status);
        $this->assertStringContainsString('not configured', (string) $attempt->failure_reason);
        $this->assertSame(24900, (int) $attempt->amount);

        // A failed checkout grants nothing.
        $this->assertSame(0, Subscription::where('user_id', $user->id)->count());
    }

    #[Test]
    public function a_checkout_in_an_unsold_currency_is_rejected_before_the_gateway(): void
    {
        $plan = $this->plan();
        $user = $this->user();

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/billing/checkout', [
            'plan_code' => $plan->code,
            'currency' => 'GBP',
            'success_url' => 'https://zaban.app/done',
            'cancel_url' => 'https://zaban.app/cancel',
        ])->assertStatus(422)->assertJsonPath('error.code', 'price_unavailable');

        $this->assertSame(0, PaymentAttempt::where('user_id', $user->id)->count());
    }

    #[Test]
    public function checkout_validates_its_input(): void
    {
        $this->actingAs($this->user(), 'sanctum')->postJson('/api/v1/billing/checkout', [
            'plan_code' => 'does-not-exist',
            'success_url' => 'not-a-url',
        ])->assertStatus(422)->assertJsonValidationErrors(['plan_code', 'success_url', 'cancel_url']);
    }

    #[Test]
    public function cancelling_at_period_end_keeps_access_until_the_period_ends(): void
    {
        $free = $this->plan(['ai_messages' => [true, 5, 'day']]);
        $this->useAsFreePlan($free);
        $plan = $this->plan(['ai_messages' => [true, 200, 'day']]);
        $user = $this->user();
        $subscription = $this->activeSubscription($user->id, $plan->id);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/billing/subscription/cancel')
            ->assertOk()
            ->assertJsonPath('data.cancel_at_period_end', true);

        $subscription->refresh();
        $this->assertSame('active', $subscription->status);
        $this->assertTrue((bool) $subscription->cancel_at_period_end);
        $this->assertSame($subscription->current_period_end->toDateTimeString(), $subscription->ends_at->toDateTimeString());
        $this->assertSame(200, (new EntitlementService)->remaining($user->id, 'ai_messages'));
    }

    #[Test]
    public function cancelling_immediately_revokes_access_now(): void
    {
        $free = $this->plan(['ai_messages' => [true, 5, 'day']]);
        $this->useAsFreePlan($free);
        $plan = $this->plan(['ai_messages' => [true, 200, 'day']]);
        $user = $this->user();
        $subscription = $this->activeSubscription($user->id, $plan->id);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/billing/subscription/cancel', ['immediately' => true])
            ->assertOk()
            ->assertJsonPath('data.status', 'canceled');

        $subscription->refresh();
        $this->assertSame('canceled', $subscription->status);
        $this->assertNotNull($subscription->canceled_at);
        $this->assertSame($free->code, (new EntitlementService)->planFor($user->id)?->code);
        $this->assertSame(5, (new EntitlementService)->remaining($user->id, 'ai_messages'));
    }

    #[Test]
    public function a_scheduled_cancellation_can_be_resumed_but_a_finished_one_cannot(): void
    {
        $plan = $this->plan(['ai_messages' => [true, 200, 'day']]);
        $user = $this->user();
        $subscription = $this->activeSubscription($user->id, $plan->id);
        $service = $this->subscriptions();

        $this->assertTrue($service->cancel($subscription)->ok);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/billing/subscription/resume')
            ->assertOk()
            ->assertJsonPath('data.cancel_at_period_end', false);

        $subscription->refresh();
        $this->assertFalse((bool) $subscription->cancel_at_period_end);
        $this->assertNull($subscription->ends_at);

        // Nothing to resume once it is not scheduled for cancellation.
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/billing/subscription/resume')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'not_resumable');
    }

    #[Test]
    public function cancelling_twice_is_refused(): void
    {
        $plan = $this->plan();
        $user = $this->user();
        $subscription = $this->activeSubscription($user->id, $plan->id);
        $service = $this->subscriptions();

        $this->assertTrue($service->cancel($subscription, true)->ok);
        $result = $service->cancel($subscription->refresh(), true);
        $this->assertFalse($result->ok);
        $this->assertSame('already_canceled', $result->errorCode);
    }

    #[Test]
    public function changing_plan_moves_entitlements_with_it(): void
    {
        $small = $this->plan(['ai_messages' => [true, 50, 'day']]);
        $big = $this->plan(['ai_messages' => [true, 500, 'day']], ['interval' => 'annual']);
        $user = $this->user();
        $subscription = $this->activeSubscription($user->id, $small->id);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/billing/subscription/change-plan', [
            'plan_code' => $big->code,
            'currency' => 'TRY',
        ])->assertOk()->assertJsonPath('data.plan.code', $big->code);

        $this->assertSame(500, (new EntitlementService)->remaining($user->id, 'ai_messages'));
        $this->assertTrue($subscription->refresh()->current_period_end->greaterThan(now()->addMonths(11)));
    }

    #[Test]
    public function a_gateway_managed_subscription_cannot_change_plan_while_the_gateway_is_unavailable(): void
    {
        $small = $this->plan(['ai_messages' => [true, 50, 'day']]);
        $big = $this->plan(['ai_messages' => [true, 500, 'day']]);
        $user = $this->user();
        $subscription = $this->activeSubscription($user->id, $small->id, ['gateway_subscription_id' => 'sub_'.Str::random(10)]);

        $result = $this->subscriptions()->changePlan($subscription, $big, $big->prices()->first());

        $this->assertFalse($result->ok);
        $this->assertSame('gateway_unavailable', $result->errorCode);
        $this->assertSame($small->id, $subscription->refresh()->plan_id);
    }

    #[Test]
    public function reconciling_without_a_configured_gateway_reports_why(): void
    {
        $plan = $this->plan();
        $user = $this->user();

        $local = $this->activeSubscription($user->id, $plan->id);
        $this->assertSame('not_gateway_managed', $this->subscriptions()->reconcile($local)->errorCode);

        $remote = $this->activeSubscription($user->id, $plan->id, ['gateway_subscription_id' => 'sub_'.Str::random(10)]);
        $this->assertSame('gateway_unavailable', $this->subscriptions()->reconcile($remote)->errorCode);
    }

    #[Test]
    public function a_trial_is_granted_once_per_user(): void
    {
        $plan = $this->plan(['ai_messages' => [true, 200, 'day']], ['trial_days' => 7]);
        $user = $this->user();
        $service = $this->subscriptions();

        $this->assertSame(7, $service->trialDaysFor($user, $plan));

        $trial = $service->startTrial($user, $plan, 'stripe');
        $this->assertNotNull($trial);
        $this->assertSame('trialing', $trial->status);
        $this->assertSame(200, (new EntitlementService)->remaining($user->id, 'ai_messages'));

        $this->assertTrue($service->hasUsedTrial($user->id));
        $this->assertSame(0, $service->trialDaysFor($user, $plan));
        $this->assertNull($service->startTrial($user, $plan, 'stripe'));
    }

    #[Test]
    public function a_plan_without_a_trial_never_grants_one(): void
    {
        $plan = $this->plan([], ['trial_days' => 0]);
        $user = $this->user();

        $this->assertSame(0, $this->subscriptions()->trialDaysFor($user, $plan));
        $this->assertNull($this->subscriptions()->startTrial($user, $plan, 'stripe'));
    }

    #[Test]
    public function period_ends_follow_the_plan_interval(): void
    {
        $service = $this->subscriptions();
        $from = now()->startOfDay();

        $this->assertSame(
            $from->copy()->addMonthNoOverflow()->toDateString(),
            $service->periodEnd($this->plan([], ['interval' => 'monthly']), $from)->toDateString(),
        );
        $this->assertSame(
            $from->copy()->addMonthsNoOverflow(3)->toDateString(),
            $service->periodEnd($this->plan([], ['interval' => 'quarterly']), $from)->toDateString(),
        );
        $this->assertSame(
            $from->copy()->addYearNoOverflow()->toDateString(),
            $service->periodEnd($this->plan([], ['interval' => 'annual']), $from)->toDateString(),
        );
        $this->assertNull($service->periodEnd($this->plan([], ['interval' => 'lifetime']), $from));
    }

    #[Test]
    public function a_lifetime_subscription_never_expires(): void
    {
        $free = $this->plan(['ai_messages' => [true, 5, 'day']]);
        $this->useAsFreePlan($free);
        $lifetime = $this->plan(['ai_messages' => [true, null, null]], ['interval' => 'lifetime']);
        $user = $this->user();

        Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $lifetime->id,
            'gateway' => 'stripe',
            'status' => 'active',
            'current_period_start' => now()->subYears(3),
            'current_period_end' => null,
        ]);

        $this->assertSame($lifetime->code, (new EntitlementService)->planFor($user->id)?->code);
        $this->assertNull((new EntitlementService)->remaining($user->id, 'ai_messages'));
    }

    #[Test]
    public function lapsed_subscriptions_are_expired_in_bulk(): void
    {
        $plan = $this->plan();
        $user = $this->user();
        $lapsed = $this->activeSubscription($user->id, $plan->id, [
            'current_period_end' => now()->subDay(),
            'ends_at' => now()->subDay(),
        ]);
        $current = $this->activeSubscription($this->user()->id, $plan->id);

        $this->subscriptions()->expireLapsed();

        $this->assertSame('expired', $lapsed->refresh()->status);
        $this->assertSame('active', $current->refresh()->status);
    }

    #[Test]
    public function invoices_are_listed_for_the_owner_only(): void
    {
        $user = $this->user();
        $other = $this->user();

        foreach ([['user' => $user, 'total' => 24900], ['user' => $other, 'total' => 999]] as $row) {
            Invoice::create([
                'user_id' => $row['user']->id,
                'number' => 'ZBN-TEST-'.Str::upper(Str::random(8)),
                'status' => 'paid',
                'subtotal' => $row['total'],
                'total' => $row['total'],
                'currency' => 'TRY',
                'issued_at' => now(),
                'paid_at' => now(),
            ]);
        }

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/billing/invoices')->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame(24900, $response->json('data.0.total'));
        $this->assertSame('249.00', $response->json('data.0.total_display'));
        $this->assertSame(1, $response->json('meta.total'));
    }
}
