<?php

namespace App\Billing\Contracts;

use App\Billing\Support\CheckoutRequest;
use App\Billing\Support\CheckoutResult;
use App\Billing\Support\GatewayResult;
use App\Billing\Support\GatewaySubscriptionState;
use App\Billing\Support\WebhookEvent;

/**
 * The only surface billing code talks to. Adding a provider is a new adapter
 * plus a config entry - no service, controller or webhook change.
 *
 * Adapters never throw for an unconfigured or failing gateway: they return a
 * failed result carrying the vendor's own error, so the caller can persist the
 * reason on the payment attempt instead of guessing from an exception type.
 */
interface PaymentGatewayInterface
{
    /** Stable code used in the `gateway` column and in config. */
    public function code(): string;

    /** True only when every credential this adapter needs is present. */
    public function isAvailable(): bool;

    public function createCheckout(CheckoutRequest $request): CheckoutResult;

    /**
     * @param  bool  $atPeriodEnd  keep access until the paid period ends
     */
    public function cancelSubscription(string $gatewaySubscriptionId, bool $atPeriodEnd = true): GatewayResult;

    /** Undo a pending "cancel at period end". */
    public function resumeSubscription(string $gatewaySubscriptionId): GatewayResult;

    /** Move an existing subscription onto another gateway price. */
    public function changePlan(string $gatewaySubscriptionId, string $gatewayPriceId): GatewayResult;

    /**
     * @param  int|null  $amount  minor units; null refunds the full charge
     */
    public function refund(string $gatewayTransactionId, ?int $amount = null, string $currency = 'TRY'): GatewayResult;

    public function fetchSubscription(string $gatewaySubscriptionId): GatewaySubscriptionState;

    /**
     * Verify the signature and parse the body. Implementations must return an
     * unverified event rather than throwing, so the caller can record it.
     *
     * @param  array<string, string|array>  $headers
     */
    public function parseWebhook(string $payload, array $headers): WebhookEvent;
}
