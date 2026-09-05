<?php

namespace App\Billing\Support;

use Illuminate\Support\Carbon;

/**
 * A subscription as the gateway currently sees it, mapped onto our own status
 * vocabulary so reconciliation never has to know vendor spellings.
 */
final class GatewaySubscriptionState
{
    public function __construct(
        public bool $ok,
        public ?string $gatewaySubscriptionId = null,
        public ?string $status = null,
        public ?Carbon $currentPeriodStart = null,
        public ?Carbon $currentPeriodEnd = null,
        public ?Carbon $trialEndsAt = null,
        public ?Carbon $canceledAt = null,
        public bool $cancelAtPeriodEnd = false,
        public ?string $errorCode = null,
        public ?string $error = null,
        public array $raw = [],
    ) {}

    public static function failure(string $code, string $message, array $raw = []): self
    {
        return new self(ok: false, errorCode: $code, error: $message, raw: $raw);
    }
}
