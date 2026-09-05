<?php

namespace App\Billing\Gateways;

use App\Billing\Contracts\PaymentGatewayInterface;
use App\Billing\Support\CheckoutRequest;
use App\Billing\Support\CheckoutResult;
use App\Billing\Support\GatewayResult;
use App\Billing\Support\GatewaySubscriptionState;
use App\Billing\Support\WebhookEvent;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * PayTR iFrame API adapter.
 *
 * PayTR authenticates every call with a base64 HMAC over an ordered
 * concatenation of the request fields plus the merchant salt; the order is part
 * of the contract, so each token builder below mirrors PayTR's documented order
 * exactly. Callbacks are verified the same way.
 */
class PayTRGateway implements PaymentGatewayInterface
{
    public function __construct(
        private ?string $merchantId,
        private ?string $merchantKey,
        private ?string $merchantSalt,
        private string $apiBase = 'https://www.paytr.com',
        private bool $testMode = false,
        private int $timeout = 20,
    ) {}

    public function code(): string
    {
        return 'paytr';
    }

    public function isAvailable(): bool
    {
        return is_string($this->merchantId) && $this->merchantId !== ''
            && is_string($this->merchantKey) && $this->merchantKey !== ''
            && is_string($this->merchantSalt) && $this->merchantSalt !== '';
    }

    public function createCheckout(CheckoutRequest $request): CheckoutResult
    {
        if (! $this->isAvailable()) {
            return CheckoutResult::failure('gateway_unavailable', 'PayTR is not configured.');
        }
        if (! $request->ipAddress) {
            // PayTR binds the token to the buyer's IP and rejects the call without it.
            return CheckoutResult::failure('missing_user_ip', 'PayTR requires the buyer IP address.');
        }

        $basket = base64_encode((string) json_encode([[
            $request->planName,
            $request->amountAsDecimal(),
            1,
        ]], JSON_UNESCAPED_UNICODE));

        $amount = (string) $request->amount;   // PayTR expects kuruş, which is what we store
        $currency = strtoupper($request->currency);
        $testMode = $this->testMode ? '1' : '0';
        $noInstallment = '0';
        $maxInstallment = '0';

        $token = $this->hash(
            $this->merchantId.$request->ipAddress.$request->idempotencyKey.($request->customerEmail ?? '')
            .$amount.$basket.$noInstallment.$maxInstallment.$currency.$testMode
        );

        $payload = [
            'merchant_id' => $this->merchantId,
            'user_ip' => $request->ipAddress,
            'merchant_oid' => $request->idempotencyKey,
            'email' => $request->customerEmail ?? '',
            'payment_amount' => $amount,
            'paytr_token' => $token,
            'user_basket' => $basket,
            'debug_on' => 0,
            'no_installment' => $noInstallment,
            'max_installment' => $maxInstallment,
            'user_name' => trim(($request->buyer['name'] ?? 'Customer').' '.($request->buyer['surname'] ?? '')),
            'user_address' => $request->buyer['address'] ?? '-',
            'user_phone' => $request->buyer['phone'] ?? '-',
            'merchant_ok_url' => $request->successUrl,
            'merchant_fail_url' => $request->cancelUrl,
            'timeout_limit' => 30,
            'currency' => $currency,
            'test_mode' => $testMode,
            // Recurring plans are collected as a stored-card repeat payment.
            'recurring_payment' => $request->recurring ? 1 : 0,
        ];

        try {
            $response = Http::baseUrl($this->apiBase)->asForm()->timeout($this->timeout)
                ->post('/odeme/api/get-token', $payload);
        } catch (ConnectionException $e) {
            return CheckoutResult::failure('gateway_unreachable', $e->getMessage());
        }

        $body = $response->json();
        $body = is_array($body) ? $body : [];

        if (($body['status'] ?? '') !== 'success') {
            return CheckoutResult::failure(
                'paytr_error',
                (string) ($body['reason'] ?? 'PayTR rejected the token request.'),
                $body,
            );
        }

        $iframeToken = (string) ($body['token'] ?? '');

        return CheckoutResult::success(
            reference: $iframeToken,
            redirectUrl: rtrim($this->apiBase, '/').'/odeme/guvenli/'.$iframeToken,
            raw: $body,
        );
    }

    public function cancelSubscription(string $gatewaySubscriptionId, bool $atPeriodEnd = true): GatewayResult
    {
        if (! $this->isAvailable()) {
            return GatewayResult::failure('gateway_unavailable', 'PayTR is not configured.');
        }

        return $this->form('/odeme/repeat/cancel', [
            'merchant_id' => $this->merchantId,
            'merchant_oid' => $gatewaySubscriptionId,
            'paytr_token' => $this->hash($this->merchantId.$gatewaySubscriptionId),
        ], $gatewaySubscriptionId);
    }

    public function resumeSubscription(string $gatewaySubscriptionId): GatewayResult
    {
        // PayTR has no resume: a cancelled repeat payment is recreated by
        // running a fresh checkout. Say so rather than pretending it worked.
        return GatewayResult::failure(
            'unsupported_operation',
            'PayTR cannot resume a cancelled recurring payment; a new checkout is required.',
        );
    }

    public function changePlan(string $gatewaySubscriptionId, string $gatewayPriceId): GatewayResult
    {
        return GatewayResult::failure(
            'unsupported_operation',
            'PayTR has no plan-swap API; cancel the current recurring payment and start a new checkout.',
        );
    }

    public function refund(string $gatewayTransactionId, ?int $amount = null, string $currency = 'TRY'): GatewayResult
    {
        if (! $this->isAvailable()) {
            return GatewayResult::failure('gateway_unavailable', 'PayTR is not configured.');
        }
        if ($amount === null) {
            return GatewayResult::failure('amount_required', 'PayTR refunds require an explicit amount.');
        }

        $returnAmount = number_format($amount / 100, 2, '.', '');

        return $this->form('/odeme/iade', [
            'merchant_id' => $this->merchantId,
            'merchant_oid' => $gatewayTransactionId,
            'return_amount' => $returnAmount,
            'paytr_token' => $this->hash($this->merchantId.$gatewayTransactionId.$returnAmount),
        ], $gatewayTransactionId);
    }

    public function fetchSubscription(string $gatewaySubscriptionId): GatewaySubscriptionState
    {
        // PayTR exposes no subscription read endpoint for iFrame recurring
        // payments; local state is authoritative and is driven by callbacks.
        return GatewaySubscriptionState::failure(
            'unsupported_operation',
            'PayTR does not expose a subscription read API; state comes from callbacks only.',
        );
    }

    public function parseWebhook(string $payload, array $headers): WebhookEvent
    {
        // PayTR posts form-encoded callbacks, not JSON.
        parse_str($payload, $body);
        $body = is_array($body) ? $body : [];
        $oid = isset($body['merchant_oid']) ? (string) $body['merchant_oid'] : null;
        $status = (string) ($body['status'] ?? 'unknown');
        $type = 'payment.'.$status;

        if (! $this->isAvailable()) {
            return WebhookEvent::rejected($this->code(), 'PayTR is not configured.', $body, $type, $oid);
        }
        if ($oid === null || ! isset($body['hash'])) {
            return WebhookEvent::rejected($this->code(), 'PayTR callback is missing merchant_oid or hash.', $body, $type, $oid);
        }

        $expected = $this->hash($oid.$this->merchantSalt.$status.((string) ($body['total_amount'] ?? '')), false);
        if (! hash_equals($expected, (string) $body['hash'])) {
            return WebhookEvent::rejected($this->code(), 'PayTR hash mismatch.', $body, $type, $oid);
        }

        return WebhookEvent::verified($this->code(), $oid, $type, $body);
    }

    /** @param  bool  $appendSalt  PayTR appends the salt for API tokens but embeds it for callbacks */
    private function hash(string $data, bool $appendSalt = true): string
    {
        return base64_encode(hash_hmac('sha256', $appendSalt ? $data.$this->merchantSalt : $data, (string) $this->merchantKey, true));
    }

    private function form(string $path, array $payload, ?string $reference): GatewayResult
    {
        try {
            $response = Http::baseUrl($this->apiBase)->asForm()->timeout($this->timeout)->post($path, $payload);
        } catch (ConnectionException $e) {
            return GatewayResult::failure('gateway_unreachable', $e->getMessage());
        }

        $body = $response->json();
        $body = is_array($body) ? $body : [];

        return ($body['status'] ?? '') === 'success'
            ? GatewayResult::success($reference, $body)
            : GatewayResult::failure('paytr_error', (string) ($body['err_msg'] ?? $body['reason'] ?? 'PayTR rejected the request.'), $body);
    }
}
