<?php

namespace App\Billing\Gateways;

use App\Billing\Contracts\PaymentGatewayInterface;
use App\Billing\Support\CheckoutRequest;
use App\Billing\Support\CheckoutResult;
use App\Billing\Support\GatewayResult;
use App\Billing\Support\GatewaySubscriptionState;
use App\Billing\Support\WebhookEvent;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * iyzico subscription API adapter (IYZWSv2 HMAC authentication).
 *
 * iyzico answers 200 for business failures too, so every call is judged on the
 * body's `status` field rather than the HTTP code.
 */
class IyzicoGateway implements PaymentGatewayInterface
{
    public function __construct(
        private ?string $apiKey,
        private ?string $secretKey,
        private string $apiBase = 'https://api.iyzipay.com',
        private int $timeout = 20,
        private string $locale = 'tr',
    ) {}

    public function code(): string
    {
        return 'iyzico';
    }

    public function isAvailable(): bool
    {
        return is_string($this->apiKey) && $this->apiKey !== ''
            && is_string($this->secretKey) && $this->secretKey !== '';
    }

    public function createCheckout(CheckoutRequest $request): CheckoutResult
    {
        if (! $this->isAvailable()) {
            return CheckoutResult::failure('gateway_unavailable', 'iyzico is not configured.');
        }
        if (! $request->gatewayPriceId) {
            // iyzico bills against a pricing plan created in the merchant panel;
            // there is no inline-price equivalent.
            return CheckoutResult::failure(
                'missing_pricing_plan',
                'This plan price has no iyzico pricingPlanReferenceCode.',
            );
        }

        $buyer = $request->buyer;
        $body = [
            'locale' => $this->locale,
            'conversationId' => $request->idempotencyKey,
            'callbackUrl' => $request->successUrl,
            'pricingPlanReferenceCode' => $request->gatewayPriceId,
            'subscriptionInitialStatus' => $request->trialDays > 0 ? 'PENDING' : 'ACTIVE',
            'customer' => [
                'name' => $buyer['name'] ?? 'Customer',
                'surname' => $buyer['surname'] ?? '-',
                'email' => $request->customerEmail ?? ($buyer['email'] ?? ''),
                'gsmNumber' => $buyer['phone'] ?? '',
                'identityNumber' => $buyer['identity_number'] ?? '',
                'billingAddress' => [
                    'contactName' => trim(($buyer['name'] ?? 'Customer').' '.($buyer['surname'] ?? '')),
                    'city' => $buyer['city'] ?? 'Istanbul',
                    'country' => $buyer['country'] ?? 'Turkey',
                    'address' => $buyer['address'] ?? '-',
                    'zipCode' => $buyer['zip_code'] ?? '',
                ],
            ],
        ];

        $result = $this->call('POST', '/v2/subscription/checkoutform/initialize', $body);
        if ($result instanceof GatewayResult) {
            return CheckoutResult::failure($result->errorCode ?? 'iyzico_error', $result->error ?? 'iyzico is unreachable.');
        }

        if (($result['status'] ?? '') !== 'success') {
            return CheckoutResult::failure(
                (string) ($result['errorCode'] ?? 'iyzico_error'),
                (string) ($result['errorMessage'] ?? 'iyzico rejected the checkout form.'),
                $result,
            );
        }

        return CheckoutResult::success(
            reference: (string) ($result['data']['token'] ?? ''),
            redirectUrl: $result['data']['payWithIyzicoPageUrl'] ?? null,
            // The hosted form arrives as a script fragment the client renders.
            htmlContent: $result['data']['checkoutFormContent'] ?? null,
            raw: $result,
        );
    }

    public function cancelSubscription(string $gatewaySubscriptionId, bool $atPeriodEnd = true): GatewayResult
    {
        if (! $this->isAvailable()) {
            return GatewayResult::failure('gateway_unavailable', 'iyzico is not configured.');
        }

        // iyzico exposes one cancellation, which stops future recurrences. An
        // immediate cut-off is enforced locally by ending the period ourselves.
        $result = $this->call('POST', "/v2/subscription/subscriptions/{$gatewaySubscriptionId}/cancel", []);

        return $this->toResult($result, $gatewaySubscriptionId);
    }

    public function resumeSubscription(string $gatewaySubscriptionId): GatewayResult
    {
        if (! $this->isAvailable()) {
            return GatewayResult::failure('gateway_unavailable', 'iyzico is not configured.');
        }

        $result = $this->call('POST', "/v2/subscription/subscriptions/{$gatewaySubscriptionId}/activate", []);

        return $this->toResult($result, $gatewaySubscriptionId);
    }

    public function changePlan(string $gatewaySubscriptionId, string $gatewayPriceId): GatewayResult
    {
        if (! $this->isAvailable()) {
            return GatewayResult::failure('gateway_unavailable', 'iyzico is not configured.');
        }

        $result = $this->call('POST', "/v2/subscription/subscriptions/{$gatewaySubscriptionId}/upgrade", [
            'locale' => $this->locale,
            'newPricingPlanReferenceCode' => $gatewayPriceId,
            'upgradePeriod' => 'NOW',
            'useTrial' => false,
            'resetRecurrenceCount' => false,
        ]);

        return $this->toResult($result, $gatewaySubscriptionId);
    }

    public function refund(string $gatewayTransactionId, ?int $amount = null, string $currency = 'TRY'): GatewayResult
    {
        if (! $this->isAvailable()) {
            return GatewayResult::failure('gateway_unavailable', 'iyzico is not configured.');
        }
        if ($amount === null) {
            // The refund endpoint is amount-based; there is no "refund all".
            return GatewayResult::failure('amount_required', 'iyzico refunds require an explicit amount.');
        }

        $result = $this->call('POST', '/payment/refund', [
            'locale' => $this->locale,
            'conversationId' => 'refund-'.$gatewayTransactionId,
            'paymentTransactionId' => $gatewayTransactionId,
            'price' => number_format($amount / 100, 2, '.', ''),
            'currency' => strtoupper($currency),
        ]);

        return $this->toResult($result, $gatewayTransactionId);
    }

    public function fetchSubscription(string $gatewaySubscriptionId): GatewaySubscriptionState
    {
        if (! $this->isAvailable()) {
            return GatewaySubscriptionState::failure('gateway_unavailable', 'iyzico is not configured.');
        }

        $result = $this->call('GET', "/v2/subscription/subscriptions/{$gatewaySubscriptionId}", []);
        if ($result instanceof GatewayResult) {
            return GatewaySubscriptionState::failure($result->errorCode ?? 'iyzico_error', $result->error ?? 'iyzico is unreachable.');
        }
        if (($result['status'] ?? '') !== 'success') {
            return GatewaySubscriptionState::failure(
                (string) ($result['errorCode'] ?? 'iyzico_error'),
                (string) ($result['errorMessage'] ?? 'iyzico subscription could not be read.'),
                $result,
            );
        }

        $data = $result['data'] ?? [];
        $status = $this->mapStatus((string) ($data['subscriptionStatus'] ?? ''));

        return new GatewaySubscriptionState(
            ok: true,
            gatewaySubscriptionId: $data['referenceCode'] ?? $gatewaySubscriptionId,
            status: $status,
            currentPeriodStart: $this->date($data['startDate'] ?? null),
            currentPeriodEnd: $this->date($data['endDate'] ?? null),
            trialEndsAt: $this->date($data['trialEndDate'] ?? null),
            canceledAt: $status === 'canceled' ? $this->date($data['canceledDate'] ?? null) : null,
            cancelAtPeriodEnd: $status === 'canceled',
            raw: $result,
        );
    }

    public function parseWebhook(string $payload, array $headers): WebhookEvent
    {
        $body = json_decode($payload, true);
        $body = is_array($body) ? $body : [];
        $type = (string) ($body['iyziEventType'] ?? 'unknown');
        $eventId = isset($body['iyziReferenceCode']) ? (string) $body['iyziReferenceCode'] : null;

        if (! is_string($this->secretKey) || $this->secretKey === '') {
            return WebhookEvent::rejected($this->code(), 'iyzico secret key is not configured.', $body, $type, $eventId);
        }

        $signature = null;
        foreach ($headers as $key => $value) {
            if (in_array(strtolower((string) $key), ['x-iyz-signature-v3', 'x-iyzi-signature-v3'], true)) {
                $signature = is_array($value) ? (string) ($value[0] ?? '') : (string) $value;
                break;
            }
        }
        if ($signature === null) {
            return WebhookEvent::rejected($this->code(), 'Missing iyzico signature header.', $body, $type, $eventId);
        }

        if (! hash_equals($this->webhookSignature($body), $signature)) {
            return WebhookEvent::rejected($this->code(), 'iyzico signature mismatch.', $body, $type, $eventId);
        }

        return WebhookEvent::verified($this->code(), $eventId, $type, $body);
    }

    /**
     * V3 notification signature: HMAC-SHA256 over the secret key concatenated
     * with the notification's identifying fields, base64 encoded.
     */
    public function webhookSignature(array $body): string
    {
        $data = $this->secretKey
            .($body['iyziEventType'] ?? '')
            .($body['iyziReferenceCode'] ?? '')
            .($body['subscriptionReferenceCode'] ?? $body['iyziPaymentId'] ?? '')
            .($body['status'] ?? '');

        return base64_encode(hash_hmac('sha256', $data, (string) $this->secretKey, true));
    }

    private function mapStatus(string $status): string
    {
        return match (strtoupper($status)) {
            'ACTIVE' => 'active',
            'PENDING' => 'incomplete',
            'UNPAID' => 'past_due',
            'UPGRADED', 'CANCELED' => 'canceled',
            'EXPIRED' => 'expired',
            default => 'incomplete',
        };
    }

    private function date(mixed $value): ?Carbon
    {
        if (! $value) {
            return null;
        }
        if (is_int($value) || ctype_digit((string) $value)) {
            // iyzico sends epoch milliseconds on some endpoints.
            return Carbon::createFromTimestampMs((int) $value);
        }

        return rescue(fn () => Carbon::parse((string) $value), null, false);
    }

    /** @return array<string, mixed>|GatewayResult */
    private function call(string $method, string $path, array $body)
    {
        $encoded = $body === [] && $method === 'GET' ? '' : json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $encoded = $encoded === false ? '' : $encoded;

        try {
            $request = Http::baseUrl($this->apiBase)
                ->withHeaders($this->authHeaders($path, $encoded))
                ->acceptJson()
                ->timeout($this->timeout);

            $response = $method === 'GET'
                ? $request->get($path)
                : $request->withBody($encoded === '' ? '{}' : $encoded, 'application/json')->post($path);
        } catch (ConnectionException $e) {
            return GatewayResult::failure('gateway_unreachable', $e->getMessage());
        }

        return $this->decode($response);
    }

    /**
     * IYZWSv2: hex HMAC of randomKey + uri path + body, then the whole
     * authorization parameter string base64 encoded.
     */
    private function authHeaders(string $uriPath, string $body): array
    {
        $randomKey = (string) now()->getTimestampMs().Str::random(12);
        $signature = hash_hmac('sha256', $randomKey.$uriPath.$body, (string) $this->secretKey);
        $params = 'apiKey:'.$this->apiKey.'&randomKey:'.$randomKey.'&signature:'.$signature;

        return [
            'Authorization' => 'IYZWSv2 '.base64_encode($params),
            'x-iyzi-rnd' => $randomKey,
            'x-iyzi-client-version' => 'zaban-billing-1',
            'Content-Type' => 'application/json',
        ];
    }

    /** @param array<string, mixed>|GatewayResult $result */
    private function toResult($result, ?string $reference): GatewayResult
    {
        if ($result instanceof GatewayResult) {
            return $result;
        }

        return ($result['status'] ?? '') === 'success'
            ? GatewayResult::success($result['data']['referenceCode'] ?? $reference, $result)
            : GatewayResult::failure(
                (string) ($result['errorCode'] ?? 'iyzico_error'),
                (string) ($result['errorMessage'] ?? 'iyzico rejected the request.'),
                $result,
            );
    }

    private function decode(Response $response): array
    {
        $decoded = $response->json();
        if (is_array($decoded)) {
            return $decoded;
        }

        return ['status' => 'failure', 'errorCode' => (string) $response->status(), 'errorMessage' => 'Unreadable iyzico response.'];
    }
}
