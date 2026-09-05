<?php

namespace App\Billing\Support;

use App\Models\PaymentAttempt;

/** Pairs the gateway's answer with the attempt row that recorded it. */
final class CheckoutOutcome
{
    public function __construct(
        public bool $ok,
        public PaymentAttempt $attempt,
        public CheckoutResult $result,
    ) {}
}
