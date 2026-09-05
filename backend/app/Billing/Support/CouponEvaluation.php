<?php

namespace App\Billing\Support;

use App\Models\Coupon;

/** Result of validating a coupon against a user and an amount. */
final class CouponEvaluation
{
    public function __construct(
        public bool $ok,
        public ?Coupon $coupon = null,
        public int $discount = 0,
        public int $amountAfterDiscount = 0,
        public ?string $reasonCode = null,
        public ?string $message = null,
    ) {}

    public static function invalid(string $code, string $message): self
    {
        return new self(false, null, 0, 0, $code, $message);
    }
}
