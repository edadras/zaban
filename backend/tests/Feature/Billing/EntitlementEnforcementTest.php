<?php

namespace Tests\Feature\Billing;

use App\Models\EntitlementUsage;
use App\Models\Subscription;
use App\Services\Billing\EntitlementService;
use PHPUnit\Framework\Attributes\Test;

class EntitlementEnforcementTest extends BillingTestCase
{
    private function service(): EntitlementService
    {
        // A fresh instance per assertion block: the service memoises plan
        // lookups for the life of a request, and each test spans several.
        return new EntitlementService;
    }

    #[Test]
    public function a_user_without_a_subscription_falls_back_to_the_free_plan(): void
    {
        $free = $this->plan([
            'ai_messages' => [true, 3, 'day'],
            'premium_tutor' => [false, 0, 'month'],
        ]);
        $this->useAsFreePlan($free);
        $user = $this->user();

        $this->assertSame($free->code, $this->service()->planFor($user->id)?->code);
        $this->assertTrue($this->service()->allows($user->id, 'ai_messages'));
        $this->assertSame(3, $this->service()->remaining($user->id, 'ai_messages'));
        $this->assertFalse($this->service()->allows($user->id, 'premium_tutor'));
        $this->assertSame(0, $this->service()->remaining($user->id, 'premium_tutor'));
    }

    #[Test]
    public function an_unknown_feature_is_denied(): void
    {
        $this->useAsFreePlan($this->plan(['ai_messages' => [true, 5, 'day']]));
        $user = $this->user();

        $this->assertFalse($this->service()->allows($user->id, 'speech_minutes'));
        $this->assertFalse($this->service()->consume($user->id, 'speech_minutes'));
    }

    #[Test]
    public function consuming_decrements_the_remaining_allowance(): void
    {
        $this->useAsFreePlan($this->plan(['ai_messages' => [true, 5, 'day']]));
        $user = $this->user();

        $this->assertTrue($this->service()->consume($user->id, 'ai_messages', 2));
        $this->assertSame(3, $this->service()->remaining($user->id, 'ai_messages'));
        $this->assertSame(2, $this->service()->used($user->id, 'ai_messages'));

        $usage = EntitlementUsage::where('user_id', $user->id)->where('feature', 'ai_messages')->firstOrFail();
        $this->assertSame('day', $usage->period);
        $this->assertSame(5, (int) $usage->limit_value);
    }

    #[Test]
    public function the_limit_cannot_be_exceeded_and_a_partial_spend_is_refused_whole(): void
    {
        $this->useAsFreePlan($this->plan(['ai_messages' => [true, 3, 'day']]));
        $user = $this->user();
        $service = $this->service();

        $this->assertTrue($service->consume($user->id, 'ai_messages', 2));
        // Asking for 2 with 1 left records nothing at all.
        $this->assertFalse($service->consume($user->id, 'ai_messages', 2));
        $this->assertSame(1, $service->remaining($user->id, 'ai_messages'));

        $this->assertTrue($service->consume($user->id, 'ai_messages'));
        $this->assertSame(0, $service->remaining($user->id, 'ai_messages'));
        $this->assertFalse($service->allows($user->id, 'ai_messages'));
        $this->assertFalse($service->consume($user->id, 'ai_messages'));
        $this->assertSame(3, $service->used($user->id, 'ai_messages'));
    }

    #[Test]
    public function a_daily_allowance_resets_on_the_next_day(): void
    {
        $this->useAsFreePlan($this->plan(['ai_messages' => [true, 2, 'day']]));
        $user = $this->user();

        $this->travelTo(now()->startOfDay()->addHours(9));
        $this->assertTrue($this->service()->consume($user->id, 'ai_messages', 2));
        $this->assertFalse($this->service()->allows($user->id, 'ai_messages'));

        $this->travelTo(now()->addDay());
        $this->assertTrue($this->service()->allows($user->id, 'ai_messages'));
        $this->assertSame(2, $this->service()->remaining($user->id, 'ai_messages'));

        // Yesterday's counter is kept as history: the new day gets its own row
        // rather than the old one being reset in place.
        $this->assertTrue($this->service()->consume($user->id, 'ai_messages'));
        $rows = EntitlementUsage::where('user_id', $user->id)->where('feature', 'ai_messages')->orderBy('period_start')->get();
        $this->assertCount(2, $rows);
        $this->assertSame([2, 1], $rows->pluck('used')->map(fn ($u) => (int) $u)->all());
        $this->travelBack();
    }

    #[Test]
    public function a_monthly_allowance_survives_a_day_change_and_resets_next_month(): void
    {
        $this->useAsFreePlan($this->plan(['speech_minutes' => [true, 10, 'month']]));
        $user = $this->user();

        $this->travelTo(now()->startOfMonth()->addDays(2));
        $this->assertTrue($this->service()->consume($user->id, 'speech_minutes', 10));
        $this->assertSame(0, $this->service()->remaining($user->id, 'speech_minutes'));

        $this->travelTo(now()->addDay());
        $this->assertSame(0, $this->service()->remaining($user->id, 'speech_minutes'));

        $this->travelTo(now()->addMonthNoOverflow()->startOfMonth());
        $this->assertSame(10, $this->service()->remaining($user->id, 'speech_minutes'));
        $this->travelBack();
    }

    #[Test]
    public function a_total_allowance_never_resets(): void
    {
        $this->useAsFreePlan($this->plan(['generated_media' => [true, 2, 'total']]));
        $user = $this->user();

        $this->assertTrue($this->service()->consume($user->id, 'generated_media', 2));
        $this->travelTo(now()->addYear());
        $this->assertFalse($this->service()->allows($user->id, 'generated_media'));
        $this->assertSame(0, $this->service()->remaining($user->id, 'generated_media'));
        $this->travelBack();
    }

    #[Test]
    public function a_null_limit_means_unlimited(): void
    {
        $this->useAsFreePlan($this->plan(['exam_prep' => [true, null, null]]));
        $user = $this->user();

        $this->assertNull($this->service()->remaining($user->id, 'exam_prep'));
        $this->assertTrue($this->service()->consume($user->id, 'exam_prep', 5000));
        $this->assertTrue($this->service()->allows($user->id, 'exam_prep'));
    }

    #[Test]
    public function an_active_subscription_overrides_the_free_plan(): void
    {
        $free = $this->plan(['ai_messages' => [true, 5, 'day']]);
        $this->useAsFreePlan($free);
        $paid = $this->plan(['ai_messages' => [true, 100, 'day']]);
        $user = $this->user();

        Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $paid->id,
            'gateway' => 'stripe',
            'status' => 'active',
            'current_period_start' => now()->subDay(),
            'current_period_end' => now()->addMonth(),
        ]);

        $this->assertSame($paid->code, $this->service()->planFor($user->id)?->code);
        $this->assertSame(100, $this->service()->remaining($user->id, 'ai_messages'));
    }

    #[Test]
    public function an_expired_period_drops_the_user_back_to_the_free_plan(): void
    {
        $free = $this->plan(['ai_messages' => [true, 5, 'day']]);
        $this->useAsFreePlan($free);
        $paid = $this->plan(['ai_messages' => [true, 100, 'day']]);
        $user = $this->user();

        Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $paid->id,
            'gateway' => 'stripe',
            'status' => 'active',
            'current_period_start' => now()->subMonths(2),
            'current_period_end' => now()->subDay(),
        ]);

        $this->assertSame($free->code, $this->service()->planFor($user->id)?->code);
        $this->assertSame(5, $this->service()->remaining($user->id, 'ai_messages'));
    }

    #[Test]
    public function a_past_due_subscription_carries_no_entitlements(): void
    {
        $free = $this->plan(['premium_tutor' => [false, 0, 'month']]);
        $this->useAsFreePlan($free);
        $paid = $this->plan(['premium_tutor' => [true, 4, 'month']]);
        $user = $this->user();

        Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $paid->id,
            'gateway' => 'stripe',
            'status' => 'past_due',
            'current_period_start' => now()->subDays(3),
            'current_period_end' => now()->addWeek(),
        ]);

        $this->assertFalse($this->service()->allows($user->id, 'premium_tutor'));
    }

    #[Test]
    public function a_trial_grants_access_until_it_ends(): void
    {
        $free = $this->plan(['ai_messages' => [true, 5, 'day']]);
        $this->useAsFreePlan($free);
        $paid = $this->plan(['ai_messages' => [true, 250, 'day']], ['trial_days' => 7]);
        $user = $this->user();

        $subscription = Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $paid->id,
            'gateway' => 'stripe',
            'status' => 'trialing',
            'trial_ends_at' => now()->addDays(7),
            'current_period_start' => now(),
            'current_period_end' => now()->addDays(7),
        ]);

        $this->assertSame(250, $this->service()->remaining($user->id, 'ai_messages'));

        $subscription->forceFill([
            'trial_ends_at' => now()->subDay(),
            'current_period_end' => now()->subDay(),
        ])->save();

        $this->assertSame(5, $this->service()->remaining($user->id, 'ai_messages'));
    }

    #[Test]
    public function upgrading_mid_period_widens_the_allowance_immediately(): void
    {
        $free = $this->plan(['ai_messages' => [true, 3, 'day']]);
        $this->useAsFreePlan($free);
        $user = $this->user();

        $this->assertTrue($this->service()->consume($user->id, 'ai_messages', 3));
        $this->assertFalse($this->service()->allows($user->id, 'ai_messages'));

        $paid = $this->plan(['ai_messages' => [true, 50, 'day']]);
        Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $paid->id,
            'gateway' => 'stripe',
            'status' => 'active',
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
        ]);

        $service = $this->service();
        $this->assertSame(47, $service->remaining($user->id, 'ai_messages'));
        $this->assertTrue($service->consume($user->id, 'ai_messages'));
        $this->assertSame(50, (int) EntitlementUsage::where('user_id', $user->id)->where('feature', 'ai_messages')->value('limit_value'));
    }

    #[Test]
    public function refunding_usage_returns_quota(): void
    {
        $this->useAsFreePlan($this->plan(['generated_media' => [true, 2, 'month']]));
        $user = $this->user();
        $service = $this->service();

        $this->assertTrue($service->consume($user->id, 'generated_media', 2));
        $service->refund($user->id, 'generated_media', 1);
        $this->assertSame(1, $service->remaining($user->id, 'generated_media'));
    }

    #[Test]
    public function usage_is_tracked_per_user(): void
    {
        $this->useAsFreePlan($this->plan(['ai_messages' => [true, 2, 'day']]));
        $one = $this->user();
        $two = $this->user();

        $this->assertTrue($this->service()->consume($one->id, 'ai_messages', 2));
        $this->assertSame(0, $this->service()->remaining($one->id, 'ai_messages'));
        $this->assertSame(2, $this->service()->remaining($two->id, 'ai_messages'));
    }
}
