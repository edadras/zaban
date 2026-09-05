<?php

namespace App\Billing\Gateways;

use App\Billing\Contracts\PaymentGatewayInterface;
use App\Billing\Support\CheckoutRequest;
use App\Billing\Support\CheckoutResult;
use App\Billing\Support\GatewayResult;
use App\Billing\Support\GatewaySubscriptionState;
use App\Billing\Support\WebhookEvent;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Stripe REST adapter (no SDK: the REST surface is stable and form-encoded).
 *
 * Nested parameters are sent the way Stripe expects them - `line_items[0][price]`
 * - which is exactly what http_build_query produces from nested arrays.
 */
class StripeGateway implements PaymentGatewayInterface
{
    public function __construct(
        private ?string $secretKey,
        private ?string $webhookSecret,
        private string $apiBase = 'https://api.stripe.com',
        private string $apiVersion = '2024-06-20',
        private int $timeout = 20,
        private int $signatureTolerance = 300,
    ) {}

    public function code(): string
    {
        return 'stripe';
    }

    public function isAvailable(): bool
    {
        return is_string($this->secretKey) && $this->secretKey !== '';
    }

    public function createCheckout(CheckoutRequest $request): CheckoutResult
    {
        if (! $this->isAvailable()) {
            return CheckoutResult::failure('gateway_unavailable', 'Stripe is not configured.');
        }

        $params = [
            'mode' => $request->recurring ? 'subscription' : 'payment',
            'success_url' => $request->successUrl,
            'cancel_url' => $request->cancelUrl,
            'client_reference_id' => (string) $request->userId,
            'metadata' => $request->metadata + ['idempotency_key' => $request->idempotencyKey],
        ];

        if ($request->gatewayPriceId) {
            $params['line_items'] = [['price' => $request->gatewayPriceId, 'quantity' => 1]];
        } else {
            // No stored price id: send an inline price so a plan can be sold
            // before it has been mirrored into the Stripe catalogue.
            $params['line_items'] = [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => strtolower($request->currency),
                    'unit_amount' => $request->amount,
                    'product_data' => ['name' => $request->planName],
                ] + ($request->recurring ? ['recurring' => ['interval' => 'month']] : []),
            ]];
        }

        if ($request->gatewayCustomerId) {
            $params['customer'] = $request->gatewayCustomerId;
        } elseif ($request->customerEmail) {
            $params['customer_email'] = $request->customerEmail;
        }

        if ($request->recurring) {
            $params['subscription_data'] = ['metadata' => $params['metadata']];
            if ($request->trialDays > 0) {
                $params['subscription_data']['trial_period_days'] = $request->trialDays;
            }
        }

        if ($request->gatewayCouponId) {
            $params['discounts'] = [['coupon' => $request->gatewayCouponId]];
        }

        try {
            $response = $this->http()
                ->withHeaders(['Idempotency-Key' => $request->idempotencyKey])
                ->post('/v1/checkout/sessions', $params);
        } catch (ConnectionException $e) {
            return CheckoutResult::failure('gateway_unreachable', $e->getMessage());
        }

        $body = $this->decode($response);
        if (! $response->successful()) {
            return CheckoutResult::failure(
                $body['error']['code'] ?? 'stripe_error',
                $body['error']['message'] ?? 'Stripe rejected the checkout session.',
                $body,
            );
        }

        return CheckoutResult::success(
            reference: (string) ($body['id'] ?? ''),
            redirectUrl: $body['url'] ?? null,
            raw: $body,
        );
    }

    public function cancelSubscription(string $gatewaySubscriptionId, bool $atPeriodEnd = true): GatewayResult
    {
        if (! $this->isAvailable()) {
            return GatewayResult::failure('gateway_unavailable', 'Stripe is not configured.');
        }

        $response = $this->send(fn (PendingRequest $http) => $atPeriodEnd
            ? $http->post("/v1/subscriptions/{$gatewaySubscriptionId}", ['cancel_at_period_end' => 'true'])
            : $http->delete("/v1/subscriptions/{$gatewaySubscriptionId}"));

        return $this->toResult($response, $gatewaySubscriptionId);
    }

    public function resumeSubscription(string $gatewaySubscriptionId): GatewayResult
    {
        if (! $this->isAvailable()) {
            return GatewayResult::failure('gateway_unavailable', 'Stripe is not configured.');
        }

        $response = $this->send(fn (PendingRequest $http) => $http->post(
            "/v1/subscriptions/{$gatewaySubscriptionId}",
            ['cancel_at_period_end' => 'false'],
        ));

        return $this->toResult($response, $gatewaySubscriptionId);
    }

    public function changePlan(string $gatewaySubscriptionId, string $gatewayPriceId): GatewayResult
    {
        if (! $this->isAvailable()) {
            return GatewayResult::failure('gateway_unavailable', 'Stripe is not configured.');
        }

        // Stripe swaps prices per subscription item, so the current item id has
        // to be read before it can be replaced.
        $current = $this->send(fn (PendingRequest $http) => $http->get("/v1/subscriptions/{$gatewaySubscriptionId}"));
        if ($current instanceof GatewayResult) {
            return $current;
        }
        $body = $this->decode($current);
        if (! $current->successful()) {
            return GatewayResult::failure(
                $body['error']['code'] ?? 'stripe_error',
                $body['error']['message'] ?? 'Stripe subscription could not be read.',
                $body,
            );
        }

        $itemId = $body['items']['data'][0]['id'] ?? null;
        if (! $itemId) {
            return GatewayResult::failure('no_subscription_item', 'Stripe subscription has no items to swap.', $body);
        }

        $response = $this->send(fn (PendingRequest $http) => $http->post("/v1/subscriptions/{$gatewaySubscriptionId}", [
            'items' => [['id' => $itemId, 'price' => $gatewayPriceId]],
            'proration_behavior' => 'create_prorations',
            'payment_behavior' => 'pending_if_incomplete',
        ]));

        return $this->toResult($response, $gatewaySubscriptionId);
    }

    public function refund(string $gatewayTransactionId, ?int $amount = null, string $currency = 'TRY'): GatewayResult
    {
        if (! $this->isAvailable()) {
            return GatewayResult::failure('gateway_unavailable', 'Stripe is not configured.');
        }

        $params = str_starts_with($gatewayTransactionId, 'pi_')
            ? ['payment_intent' => $gatewayTransactionId]
            : ['charge' => $gatewayTransactionId];
        if ($amount !== null) {
            $params['amount'] = $amount;
        }

        $response = $this->send(fn (PendingRequest $http) => $http
            ->withHeaders(['Idempotency-Key' => 'refund_'.$gatewayTransactionId.'_'.($amount ?? 'full')])
            ->post('/v1/refunds', $params));

        if ($response instanceof GatewayResult) {
            return $response;
        }
        $body = $this->decode($response);

        return $response->successful()
            ? GatewayResult::success($body['id'] ?? null, $body)
            : GatewayResult::failure(
                $body['error']['code'] ?? 'stripe_error',
                $body['error']['message'] ?? 'Stripe refused the refund.',
                $body,
            );
    }

    public function fetchSubscription(string $gatewaySubscriptionId): GatewaySubscriptionState
    {
        if (! $this->isAvailable()) {
            return GatewaySubscriptionState::failure('gateway_unavailable', 'Stripe is not configured.');
        }

        try {
            $response = $this->http()->get("/v1/subscriptions/{$gatewaySubscriptionId}");
        } catch (ConnectionException $e) {
            return GatewaySubscriptionState::failure('gateway_unreachable', $e->getMessage());
        }

        $body = $this->decode($response);
        if (! $response->successful()) {
            return GatewaySubscriptionState::failure(
                $body['error']['code'] ?? 'stripe_error',
                $body['error']['message'] ?? 'Stripe subscription could not be read.',
                $body,
            );
        }

        return $this->mapSubscription($body);
    }

    /** @param array<string, mixed> $body */
    public function mapSubscription(array $body): GatewaySubscriptionState
    {
        // Newer API versions moved the period fields onto the item; read both.
        $item = $body['items']['data'][0] ?? [];
        $start = $body['current_period_start'] ?? $item['current_period_start'] ?? null;
        $end = $body['current_period_end'] ?? $item['current_period_end'] ?? null;

        return new GatewaySubscriptionState(
            ok: true,
            gatewaySubscriptionId: $body['id'] ?? null,
            status: $this->mapStatus((string) ($body['status'] ?? '')),
            currentPeriodStart: $start ? Carbon::createFromTimestamp((int) $start) : null,
            currentPeriodEnd: $end ? Carbon::createFromTimestamp((int) $end) : null,
            trialEndsAt: isset($body['trial_end']) && $body['trial_end'] ? Carbon::createFromTimestamp((int) $body['trial_end']) : null,
            canceledAt: isset($body['canceled_at']) && $body['canceled_at'] ? Carbon::createFromTimestamp((int) $body['canceled_at']) : null,
            cancelAtPeriodEnd: (bool) ($body['cancel_at_period_end'] ?? false),
            raw: $body,
        );
    }

    public function parseWebhook(string $payload, array $headers): WebhookEvent
    {
        $body = json_decode($payload, true);
        $body = is_array($body) ? $body : [];
        $type = (string) ($body['type'] ?? 'unknown');
        $eventId = isset($body['id']) ? (string) $body['id'] : null;

        if (! is_string($this->webhookSecret) || $this->webhookSecret === '') {
            return WebhookEvent::rejected($this->code(), 'Stripe webhook secret is not configured.', $body, $type, $eventId);
        }

        $header = $this->header($headers, 'stripe-signature');
        if ($header === null) {
            return WebhookEvent::rejected($this->code(), 'Missing Stripe-Signature header.', $body, $type, $eventId);
        }

        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, null);
            if ($key === 't') {
                $timestamp = $value;
            } elseif ($key === 'v1' && $value !== null) {
                $signatures[] = $value;
            }
        }

        if ($timestamp === null || $signatures === []) {
            return WebhookEvent::rejected($this->code(), 'Malformed Stripe-Signature header.', $body, $type, $eventId);
        }

        // A replayed old event is as dangerous as a forged one.
        if (abs(time() - (int) $timestamp) > $this->signatureTolerance) {
            return WebhookEvent::rejected($this->code(), 'Stripe signature timestamp outside tolerance.', $body, $type, $eventId);
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $this->webhookSecret);
        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return WebhookEvent::verified($this->code(), $eventId, $type, $body);
            }
        }

        return WebhookEvent::rejected($this->code(), 'Stripe signature mismatch.', $body, $type, $eventId);
    }

    /** Stripe's vocabulary mapped onto the subscriptions.status column. */
    private function mapStatus(string $status): string
    {
        return match ($status) {
            'trialing' => 'trialing',
            'active' => 'active',
            'past_due', 'unpaid' => 'past_due',
            'paused' => 'paused',
            'canceled' => 'canceled',
            'incomplete_expired' => 'expired',
            default => 'incomplete',
        };
    }

    private function http(): PendingRequest
    {
        return Http::withToken($this->secretKey)
            ->baseUrl($this->apiBase)
            ->asForm()
            ->acceptJson()
            ->withHeaders(['Stripe-Version' => $this->apiVersion])
            ->timeout($this->timeout);
    }

    /** @return Response|GatewayResult */
    private function send(callable $call)
    {
        try {
            return $call($this->http());
        } catch (ConnectionException $e) {
            return GatewayResult::failure('gateway_unreachable', $e->getMessage());
        }
    }

    private function toResult($response, ?string $reference): GatewayResult
    {
        if ($response instanceof GatewayResult) {
            return $response;
        }
        $body = $this->decode($response);

        return $response->successful()
            ? GatewayResult::success($body['id'] ?? $reference, $body)
            : GatewayResult::failure(
                $body['error']['code'] ?? 'stripe_error',
                $body['error']['message'] ?? 'Stripe rejected the request.',
                $body,
            );
    }

    private function decode(Response $response): array
    {
        $decoded = $response->json();

        return is_array($decoded) ? $decoded : [];
    }

    private function header(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (strtolower((string) $key) === $name) {
                return is_array($value) ? (string) ($value[0] ?? '') : (string) $value;
            }
        }

        return null;
    }
}
