<?php

namespace Tests\Feature\Billing;

use App\Billing\Gateways\IyzicoGateway;
use App\Billing\Gateways\PayTRGateway;
use App\Billing\Gateways\StripeGateway;
use App\Billing\Support\CheckoutRequest;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

/**
 * The adapters are exercised against faked HTTP so the request shape each
 * gateway actually requires is asserted, and so an unconfigured gateway is
 * proven to fail rather than to invent a success.
 */
class GatewayAdapterTest extends BillingTestCase
{
    private function checkoutRequest(array $overrides = []): CheckoutRequest
    {
        return new CheckoutRequest(
            userId: $overrides['userId'] ?? 42,
            planCode: 'monthly',
            planName: 'Premium Monthly',
            amount: 24900,
            currency: 'TRY',
            idempotencyKey: 'zbn20260101abcdef',
            successUrl: 'https://zaban.app/done',
            cancelUrl: 'https://zaban.app/cancel',
            recurring: true,
            gatewayPriceId: array_key_exists('gatewayPriceId', $overrides) ? $overrides['gatewayPriceId'] : 'price_123',
            trialDays: $overrides['trialDays'] ?? 7,
            customerEmail: 'learner@example.com',
            ipAddress: array_key_exists('ipAddress', $overrides) ? $overrides['ipAddress'] : '198.51.100.7',
            buyer: ['name' => 'Ada', 'surname' => 'Lovelace', 'city' => 'Istanbul'],
        );
    }

    #[Test]
    public function an_unconfigured_stripe_adapter_refuses_every_operation(): void
    {
        Http::fake();
        $gateway = new StripeGateway(null, null);

        $this->assertFalse($gateway->isAvailable());
        $this->assertSame('gateway_unavailable', $gateway->createCheckout($this->checkoutRequest())->errorCode);
        $this->assertSame('gateway_unavailable', $gateway->cancelSubscription('sub_1')->errorCode);
        $this->assertSame('gateway_unavailable', $gateway->resumeSubscription('sub_1')->errorCode);
        $this->assertSame('gateway_unavailable', $gateway->changePlan('sub_1', 'price_2')->errorCode);
        $this->assertSame('gateway_unavailable', $gateway->refund('pi_1', 100)->errorCode);
        $this->assertSame('gateway_unavailable', $gateway->fetchSubscription('sub_1')->errorCode);
        $this->assertFalse($gateway->parseWebhook('{}', ['stripe-signature' => 't=1,v1=x'])->verified);

        Http::assertNothingSent();
    }

    #[Test]
    public function stripe_opens_a_subscription_checkout_with_the_documented_parameters(): void
    {
        Http::fake(['api.stripe.com/v1/checkout/sessions' => Http::response([
            'id' => 'cs_test_123',
            'url' => 'https://checkout.stripe.com/c/pay/cs_test_123',
        ])]);

        $gateway = new StripeGateway('sk_test_x', 'whsec_x');
        $result = $gateway->createCheckout($this->checkoutRequest());

        $this->assertTrue($result->ok);
        $this->assertSame('cs_test_123', $result->reference);
        $this->assertSame('https://checkout.stripe.com/c/pay/cs_test_123', $result->redirectUrl);

        Http::assertSent(function (Request $request) {
            parse_str($request->body(), $body);

            return $request->url() === 'https://api.stripe.com/v1/checkout/sessions'
                && $request->hasHeader('Authorization', 'Bearer sk_test_x')
                && $request->hasHeader('Idempotency-Key', 'zbn20260101abcdef')
                && $body['mode'] === 'subscription'
                && $body['line_items'][0]['price'] === 'price_123'
                && $body['line_items'][0]['quantity'] === '1'
                && $body['subscription_data']['trial_period_days'] === '7'
                && $body['client_reference_id'] === '42'
                && $body['success_url'] === 'https://zaban.app/done';
        });
    }

    #[Test]
    public function stripe_sends_an_inline_price_when_the_plan_has_no_gateway_price(): void
    {
        Http::fake(['api.stripe.com/*' => Http::response(['id' => 'cs_test_456', 'url' => 'https://checkout.stripe.com/x'])]);

        (new StripeGateway('sk_test_x', 'whsec_x'))->createCheckout($this->checkoutRequest(['gatewayPriceId' => null]));

        Http::assertSent(function (Request $request) {
            parse_str($request->body(), $body);

            return $body['line_items'][0]['price_data']['unit_amount'] === '24900'
                && $body['line_items'][0]['price_data']['currency'] === 'try';
        });
    }

    #[Test]
    public function a_stripe_error_response_is_surfaced_verbatim(): void
    {
        Http::fake(['api.stripe.com/*' => Http::response([
            'error' => ['code' => 'resource_missing', 'message' => 'No such price: price_123'],
        ], 400)]);

        $result = (new StripeGateway('sk_test_x', 'whsec_x'))->createCheckout($this->checkoutRequest());

        $this->assertFalse($result->ok);
        $this->assertSame('resource_missing', $result->errorCode);
        $this->assertSame('No such price: price_123', $result->error);
    }

    #[Test]
    public function stripe_cancellation_differs_between_period_end_and_immediate(): void
    {
        Http::fake(['api.stripe.com/*' => Http::response(['id' => 'sub_1', 'status' => 'active'])]);
        $gateway = new StripeGateway('sk_test_x', 'whsec_x');

        $this->assertTrue($gateway->cancelSubscription('sub_1', true)->ok);
        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && $r->url() === 'https://api.stripe.com/v1/subscriptions/sub_1'
            && str_contains($r->body(), 'cancel_at_period_end=true'));

        $this->assertTrue($gateway->cancelSubscription('sub_1', false)->ok);
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE'
            && $r->url() === 'https://api.stripe.com/v1/subscriptions/sub_1');
    }

    #[Test]
    public function stripe_subscription_state_is_mapped_onto_our_vocabulary(): void
    {
        $start = now()->subDays(3)->getTimestamp();
        $end = now()->addDays(27)->getTimestamp();
        Http::fake(['api.stripe.com/*' => Http::response([
            'id' => 'sub_1',
            'status' => 'past_due',
            'cancel_at_period_end' => true,
            'items' => ['data' => [['id' => 'si_1', 'current_period_start' => $start, 'current_period_end' => $end]]],
        ])]);

        $state = (new StripeGateway('sk_test_x', 'whsec_x'))->fetchSubscription('sub_1');

        $this->assertTrue($state->ok);
        $this->assertSame('past_due', $state->status);
        $this->assertTrue($state->cancelAtPeriodEnd);
        $this->assertSame($end, $state->currentPeriodEnd->getTimestamp());
    }

    #[Test]
    public function stripe_webhook_verification_accepts_only_a_matching_signature(): void
    {
        $gateway = new StripeGateway('sk_test_x', 'whsec_x');
        $payload = json_encode(['id' => 'evt_1', 'type' => 'invoice.paid', 'data' => ['object' => []]]);
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$payload, 'whsec_x');

        $event = $gateway->parseWebhook($payload, ['Stripe-Signature' => 't='.$timestamp.',v1='.$signature]);
        $this->assertTrue($event->verified);
        $this->assertSame('evt_1', $event->eventId);
        $this->assertSame('invoice.paid', $event->type);

        // A signature over a different body must not verify.
        $tampered = str_replace('invoice.paid', 'invoice.void', $payload);
        $this->assertFalse($gateway->parseWebhook($tampered, ['Stripe-Signature' => 't='.$timestamp.',v1='.$signature])->verified);
    }

    #[Test]
    public function an_unconfigured_iyzico_adapter_refuses_every_operation(): void
    {
        Http::fake();
        $gateway = new IyzicoGateway(null, null);

        $this->assertFalse($gateway->isAvailable());
        $this->assertSame('gateway_unavailable', $gateway->createCheckout($this->checkoutRequest())->errorCode);
        $this->assertSame('gateway_unavailable', $gateway->cancelSubscription('sub_1')->errorCode);
        $this->assertSame('gateway_unavailable', $gateway->refund('tx_1', 100)->errorCode);
        $this->assertSame('gateway_unavailable', $gateway->fetchSubscription('sub_1')->errorCode);
        $this->assertFalse($gateway->parseWebhook('{}', [])->verified);

        Http::assertNothingSent();
    }

    #[Test]
    public function iyzico_signs_the_subscription_request_and_needs_a_pricing_plan(): void
    {
        Http::fake(['api.iyzipay.com/*' => Http::response([
            'status' => 'success',
            'data' => ['token' => 'tok_1', 'checkoutFormContent' => '<script>iyzico</script>'],
        ])]);

        $gateway = new IyzicoGateway('api-key', 'secret-key');
        $result = $gateway->createCheckout($this->checkoutRequest());

        $this->assertTrue($result->ok);
        $this->assertSame('tok_1', $result->reference);
        $this->assertSame('<script>iyzico</script>', $result->htmlContent);

        Http::assertSent(function (Request $request) {
            $body = json_decode($request->body(), true);

            return $request->url() === 'https://api.iyzipay.com/v2/subscription/checkoutform/initialize'
                && str_starts_with($request->header('Authorization')[0], 'IYZWSv2 ')
                && $request->hasHeader('x-iyzi-rnd')
                && $body['pricingPlanReferenceCode'] === 'price_123'
                && $body['subscriptionInitialStatus'] === 'PENDING'
                && $body['customer']['email'] === 'learner@example.com';
        });

        $missing = $gateway->createCheckout($this->checkoutRequest(['gatewayPriceId' => null]));
        $this->assertFalse($missing->ok);
        $this->assertSame('missing_pricing_plan', $missing->errorCode);
    }

    #[Test]
    public function an_iyzico_business_failure_is_a_failure_even_at_http_200(): void
    {
        Http::fake(['api.iyzipay.com/*' => Http::response([
            'status' => 'failure',
            'errorCode' => '5008',
            'errorMessage' => 'Pricing plan not found',
        ], 200)]);

        $result = (new IyzicoGateway('api-key', 'secret-key'))->createCheckout($this->checkoutRequest());

        $this->assertFalse($result->ok);
        $this->assertSame('5008', $result->errorCode);
        $this->assertSame('Pricing plan not found', $result->error);
    }

    #[Test]
    public function an_unconfigured_paytr_adapter_refuses_every_operation(): void
    {
        Http::fake();
        $gateway = new PayTRGateway(null, null, null);

        $this->assertFalse($gateway->isAvailable());
        $this->assertSame('gateway_unavailable', $gateway->createCheckout($this->checkoutRequest())->errorCode);
        $this->assertSame('gateway_unavailable', $gateway->cancelSubscription('oid_1')->errorCode);
        $this->assertSame('gateway_unavailable', $gateway->refund('oid_1', 100)->errorCode);
        $this->assertFalse($gateway->parseWebhook('merchant_oid=x&status=success', [])->verified);

        Http::assertNothingSent();
    }

    #[Test]
    public function paytr_operations_it_does_not_support_say_so(): void
    {
        $gateway = new PayTRGateway('m1', 'key', 'salt');

        $this->assertSame('unsupported_operation', $gateway->resumeSubscription('oid_1')->errorCode);
        $this->assertSame('unsupported_operation', $gateway->changePlan('oid_1', 'plan_2')->errorCode);
        $this->assertSame('unsupported_operation', $gateway->fetchSubscription('oid_1')->errorCode);
        $this->assertSame('missing_user_ip', $gateway->createCheckout($this->checkoutRequest(['ipAddress' => null]))->errorCode);
    }

    #[Test]
    public function paytr_requests_a_token_and_returns_the_iframe_url(): void
    {
        Http::fake(['www.paytr.com/odeme/api/get-token' => Http::response(['status' => 'success', 'token' => 'tkn_1'])]);

        $result = (new PayTRGateway('m1', 'key', 'salt'))->createCheckout($this->checkoutRequest());

        $this->assertTrue($result->ok);
        $this->assertSame('https://www.paytr.com/odeme/guvenli/tkn_1', $result->redirectUrl);

        Http::assertSent(function (Request $request) {
            parse_str($request->body(), $body);
            $expected = base64_encode(hash_hmac(
                'sha256',
                'm1'.'198.51.100.7'.'zbn20260101abcdef'.'learner@example.com'.'24900'.$body['user_basket'].'0'.'0'.'TRY'.'0'.'salt',
                'key',
                true,
            ));

            return $body['merchant_oid'] === 'zbn20260101abcdef'
                && $body['payment_amount'] === '24900'
                && $body['paytr_token'] === $expected;
        });
    }

    #[Test]
    public function a_paytr_callback_is_verified_against_the_merchant_hash(): void
    {
        $gateway = new PayTRGateway('m1', 'key', 'salt');
        $fields = ['merchant_oid' => 'zbn123', 'status' => 'success', 'total_amount' => '24900'];
        $hash = base64_encode(hash_hmac('sha256', 'zbn123'.'salt'.'success'.'24900', 'key', true));

        $event = $gateway->parseWebhook(http_build_query($fields + ['hash' => $hash]), []);
        $this->assertTrue($event->verified);
        $this->assertSame('payment.success', $event->type);
        $this->assertSame('zbn123', $event->eventId);

        $forged = $gateway->parseWebhook(http_build_query($fields + ['hash' => 'not-the-hash']), []);
        $this->assertFalse($forged->verified);
        $this->assertSame('PayTR hash mismatch.', $forged->error);
    }
}
