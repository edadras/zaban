<?php

namespace App\Services\Billing;

use App\Billing\Support\CouponEvaluation;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Coupon rules live here, not in the gateway: a code has to be judged before a
 * checkout is opened, and the discounted amount is what we record on the
 * payment attempt and the invoice.
 */
class CouponService
{
    /**
     * @param  int  $amount  minor units the coupon would apply to
     */
    public function evaluate(string $code, int $userId, int $amount, string $currency): CouponEvaluation
    {
        $coupon = Coupon::whereRaw('LOWER(code) = ?', [mb_strtolower(trim($code))])->first();

        if (! $coupon) {
            return CouponEvaluation::invalid('coupon_not_found', 'That coupon code does not exist.');
        }
        if (! $coupon->is_active) {
            return CouponEvaluation::invalid('coupon_inactive', 'That coupon is no longer active.');
        }
        if ($coupon->starts_at && $coupon->starts_at->isFuture()) {
            return CouponEvaluation::invalid('coupon_not_started', 'That coupon is not valid yet.');
        }
        if ($coupon->expires_at && $coupon->expires_at->isPast()) {
            return CouponEvaluation::invalid('coupon_expired', 'That coupon has expired.');
        }
        if ($coupon->max_redemptions !== null && $coupon->redemption_count >= $coupon->max_redemptions) {
            return CouponEvaluation::invalid('coupon_exhausted', 'That coupon has reached its redemption limit.');
        }
        if ($coupon->discount_type === 'fixed' && $coupon->currency && strtoupper($coupon->currency) !== strtoupper($currency)) {
            return CouponEvaluation::invalid('coupon_currency_mismatch', 'That coupon cannot be used in this currency.');
        }
        if ($this->alreadyRedeemed($coupon->id, $userId)) {
            return CouponEvaluation::invalid('coupon_already_redeemed', 'You have already used that coupon.');
        }

        $discount = $this->discountFor($coupon, $amount);

        return new CouponEvaluation(
            ok: true,
            coupon: $coupon,
            discount: $discount,
            amountAfterDiscount: max(0, $amount - $discount),
        );
    }

    public function discountFor(Coupon $coupon, int $amount): int
    {
        $discount = $coupon->discount_type === 'percent'
            ? (int) floor($amount * min(100, (int) $coupon->discount_value) / 100)
            : (int) $coupon->discount_value;

        return max(0, min($amount, $discount));
    }

    public function alreadyRedeemed(int $couponId, int $userId): bool
    {
        return CouponRedemption::where('coupon_id', $couponId)->where('user_id', $userId)->exists();
    }

    /**
     * Redeem under a row lock so two concurrent checkouts cannot push the
     * counter past max_redemptions; the unique (coupon, user) index is the
     * second guard against a double redemption by one person.
     */
    public function redeem(Coupon $coupon, int $userId, ?int $subscriptionId = null): CouponEvaluation
    {
        return DB::transaction(function () use ($coupon, $userId, $subscriptionId) {
            /** @var Coupon|null $locked */
            $locked = Coupon::whereKey($coupon->id)->lockForUpdate()->first();
            if (! $locked) {
                return CouponEvaluation::invalid('coupon_not_found', 'That coupon code does not exist.');
            }
            if (! $locked->is_active) {
                return CouponEvaluation::invalid('coupon_inactive', 'That coupon is no longer active.');
            }
            if ($locked->max_redemptions !== null && $locked->redemption_count >= $locked->max_redemptions) {
                return CouponEvaluation::invalid('coupon_exhausted', 'That coupon has reached its redemption limit.');
            }

            try {
                CouponRedemption::create([
                    'coupon_id' => $locked->id,
                    'user_id' => $userId,
                    'subscription_id' => $subscriptionId,
                    'redeemed_at' => now(),
                ]);
            } catch (QueryException $e) {
                if ($this->isDuplicate($e)) {
                    return CouponEvaluation::invalid('coupon_already_redeemed', 'You have already used that coupon.');
                }
                throw $e;
            }

            $locked->increment('redemption_count');

            return new CouponEvaluation(ok: true, coupon: $locked->refresh());
        });
    }

    private function isDuplicate(QueryException $e): bool
    {
        return (int) ($e->errorInfo[1] ?? 0) === 1062 || str_contains($e->getMessage(), 'Duplicate entry');
    }
}
