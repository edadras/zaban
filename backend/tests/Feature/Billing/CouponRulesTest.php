<?php

namespace Tests\Feature\Billing;

use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Services\Billing\CouponService;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;

class CouponRulesTest extends BillingTestCase
{
    private function coupons(): CouponService
    {
        return new CouponService;
    }

    private function coupon(array $attributes = []): Coupon
    {
        return Coupon::create($attributes + [
            'code' => 'TEST'.Str::upper(Str::random(8)),
            'discount_type' => 'percent',
            'discount_value' => 25,
            'is_active' => true,
        ]);
    }

    #[Test]
    public function a_percent_coupon_discounts_proportionally(): void
    {
        $coupon = $this->coupon(['discount_type' => 'percent', 'discount_value' => 25]);
        $result = $this->coupons()->evaluate($coupon->code, $this->user()->id, 24900, 'TRY');

        $this->assertTrue($result->ok);
        $this->assertSame(6225, $result->discount);
        $this->assertSame(18675, $result->amountAfterDiscount);
    }

    #[Test]
    public function a_fixed_coupon_discounts_its_face_value_and_never_more_than_the_price(): void
    {
        $coupon = $this->coupon(['discount_type' => 'fixed', 'discount_value' => 5000, 'currency' => 'TRY']);
        $this->assertSame(5000, $this->coupons()->evaluate($coupon->code, $this->user()->id, 24900, 'TRY')->discount);

        $result = $this->coupons()->evaluate($coupon->code, $this->user()->id, 3000, 'TRY');
        $this->assertSame(3000, $result->discount);
        $this->assertSame(0, $result->amountAfterDiscount);
    }

    #[Test]
    public function a_fixed_coupon_is_refused_in_another_currency(): void
    {
        $coupon = $this->coupon(['discount_type' => 'fixed', 'discount_value' => 5000, 'currency' => 'TRY']);
        $result = $this->coupons()->evaluate($coupon->code, $this->user()->id, 999, 'USD');

        $this->assertFalse($result->ok);
        $this->assertSame('coupon_currency_mismatch', $result->reasonCode);
    }

    #[Test]
    public function codes_are_matched_case_insensitively_but_must_exist(): void
    {
        $coupon = $this->coupon();
        $this->assertTrue($this->coupons()->evaluate(Str::lower($coupon->code), $this->user()->id, 24900, 'TRY')->ok);
        $this->assertSame('coupon_not_found', $this->coupons()->evaluate('NOPE-'.Str::random(6), $this->user()->id, 24900, 'TRY')->reasonCode);
    }

    #[Test]
    public function an_inactive_coupon_is_refused(): void
    {
        $coupon = $this->coupon(['is_active' => false]);
        $this->assertSame('coupon_inactive', $this->coupons()->evaluate($coupon->code, $this->user()->id, 24900, 'TRY')->reasonCode);
    }

    #[Test]
    public function the_date_window_is_enforced_at_both_ends(): void
    {
        $future = $this->coupon(['starts_at' => now()->addWeek()]);
        $this->assertSame('coupon_not_started', $this->coupons()->evaluate($future->code, $this->user()->id, 24900, 'TRY')->reasonCode);

        $past = $this->coupon(['expires_at' => now()->subMinute()]);
        $this->assertSame('coupon_expired', $this->coupons()->evaluate($past->code, $this->user()->id, 24900, 'TRY')->reasonCode);

        $open = $this->coupon(['starts_at' => now()->subDay(), 'expires_at' => now()->addDay()]);
        $this->assertTrue($this->coupons()->evaluate($open->code, $this->user()->id, 24900, 'TRY')->ok);
    }

    #[Test]
    public function max_redemptions_closes_the_coupon(): void
    {
        $coupon = $this->coupon(['max_redemptions' => 2]);
        $service = $this->coupons();

        $this->assertTrue($service->redeem($coupon, $this->user()->id)->ok);
        $this->assertTrue($service->redeem($coupon, $this->user()->id)->ok);
        $this->assertSame(2, (int) $coupon->refresh()->redemption_count);

        $third = $this->user();
        $this->assertSame('coupon_exhausted', $service->redeem($coupon, $third->id)->reasonCode);
        $this->assertSame('coupon_exhausted', $service->evaluate($coupon->code, $third->id, 24900, 'TRY')->reasonCode);
        $this->assertSame(2, CouponRedemption::where('coupon_id', $coupon->id)->count());
    }

    #[Test]
    public function a_user_can_only_redeem_a_coupon_once(): void
    {
        $coupon = $this->coupon();
        $user = $this->user();
        $service = $this->coupons();

        $this->assertTrue($service->redeem($coupon, $user->id)->ok);

        $second = $service->redeem($coupon, $user->id);
        $this->assertFalse($second->ok);
        $this->assertSame('coupon_already_redeemed', $second->reasonCode);

        $this->assertSame('coupon_already_redeemed', $service->evaluate($coupon->code, $user->id, 24900, 'TRY')->reasonCode);
        $this->assertSame(1, CouponRedemption::where('coupon_id', $coupon->id)->count());
        $this->assertSame(1, (int) $coupon->refresh()->redemption_count);
    }

    #[Test]
    public function another_user_may_still_redeem_the_same_coupon(): void
    {
        $coupon = $this->coupon();
        $service = $this->coupons();

        $this->assertTrue($service->redeem($coupon, $this->user()->id)->ok);
        $this->assertTrue($service->redeem($coupon, $this->user()->id)->ok);
        $this->assertSame(2, (int) $coupon->refresh()->redemption_count);
    }

    #[Test]
    public function applying_a_coupon_over_http_prices_it_without_redeeming_it(): void
    {
        $plan = $this->plan(['ai_messages' => [true, 10, 'day']], [], 24900);
        $coupon = $this->coupon(['discount_type' => 'percent', 'discount_value' => 50]);
        $user = $this->user();

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/billing/coupons/apply', [
            'code' => $coupon->code,
            'plan_code' => $plan->code,
            'currency' => 'TRY',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.discount', 12450)
            ->assertJsonPath('data.amount_due', 12450)
            ->assertJsonPath('data.plan_code', $plan->code);

        $this->assertSame(0, CouponRedemption::where('coupon_id', $coupon->id)->count());
        $this->assertSame(0, (int) $coupon->refresh()->redemption_count);
    }

    #[Test]
    public function an_invalid_coupon_is_rejected_over_http_with_its_reason(): void
    {
        $plan = $this->plan([], [], 24900);
        $coupon = $this->coupon(['expires_at' => now()->subDay()]);

        $this->actingAs($this->user(), 'sanctum')
            ->postJson('/api/v1/billing/coupons/apply', ['code' => $coupon->code, 'plan_code' => $plan->code])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'coupon_expired');
    }

    #[Test]
    public function applying_a_coupon_requires_authentication(): void
    {
        $plan = $this->plan();
        $this->postJson('/api/v1/billing/coupons/apply', ['code' => 'X', 'plan_code' => $plan->code])
            ->assertStatus(401);
    }
}
