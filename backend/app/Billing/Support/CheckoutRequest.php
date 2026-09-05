<?php

namespace App\Billing\Support;

/**
 * Everything a gateway needs to open a checkout, expressed in our terms.
 *
 * Gateways differ wildly in what they demand (Stripe wants a price id, PayTR a
 * basket and buyer IP, iyzico a pricing plan reference plus a full buyer
 * record), so this carries the union and each adapter takes what it needs.
 */
final class CheckoutRequest
{
    /**
     * @param  int  $amount  minor units (kuruş/cents) after discount
     * @param  array<string, string>  $buyer  name, surname, email, phone, identity_number, address, city, country
     * @param  array<string, string>  $metadata  echoed back on the webhook
     */
    public function __construct(
        public int $userId,
        public string $planCode,
        public string $planName,
        public int $amount,
        public string $currency,
        public string $idempotencyKey,
        public string $successUrl,
        public string $cancelUrl,
        public bool $recurring = true,
        public ?string $gatewayPriceId = null,
        public ?string $gatewayCustomerId = null,
        public ?string $gatewayCouponId = null,
        public int $trialDays = 0,
        public ?string $customerEmail = null,
        public ?string $ipAddress = null,
        public array $buyer = [],
        public array $metadata = [],
    ) {}

    /** Decimal string, which is what the Turkish gateways expect on the wire. */
    public function amountAsDecimal(): string
    {
        return number_format($this->amount / 100, 2, '.', '');
    }
}
