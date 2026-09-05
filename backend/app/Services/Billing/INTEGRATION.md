# Phase 12 — billing integration notes

Everything under `app/Billing`, `app/Services/Billing`, `app/Http/**/Billing`,
`database/seeders/PlanSeeder.php` and `tests/Feature/Billing` is self-contained.
Three things live outside those paths and have to be added by whoever owns them.

---

## 1. `config/billing.php` (required)

Without this file `App\Billing\BillingConfig` falls back to its built-in
defaults, which have **no credentials**, so every gateway reports itself
unavailable and every checkout fails with `gateway_unavailable`. That is the
correct behaviour for an unconfigured install, not a bug — but nothing can be
sold until the file exists.

```php
<?php

/**
 * Payment gateways. Adding one is a driver class plus a block here; no service,
 * controller or webhook change.
 */
return [
    'default' => env('BILLING_GATEWAY', 'iyzico'),
    'currency' => env('BILLING_CURRENCY', 'TRY'),

    // Plan every user falls back to with no paid subscription. Must match a
    // plans.code row (PlanSeeder creates `free`).
    'free_plan' => env('BILLING_FREE_PLAN', 'free'),

    // Portion of a gross price that is tax, used for the invoice breakdown.
    // 0.20 = prices include 20% KDV. 0 disables the line.
    'tax_rate' => (float) env('BILLING_TAX_RATE', 0),

    'invoice' => [
        'prefix' => env('BILLING_INVOICE_PREFIX', 'ZBN'),
        'padding' => (int) env('BILLING_INVOICE_PADDING', 6),
    ],

    'gateways' => [
        'stripe' => [
            'driver' => App\Billing\Gateways\StripeGateway::class,
            'secret_key' => env('STRIPE_SECRET_KEY'),
            'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
            'api_base' => env('STRIPE_API_BASE', 'https://api.stripe.com'),
            'api_version' => env('STRIPE_API_VERSION', '2024-06-20'),
            'timeout' => (int) env('STRIPE_TIMEOUT', 20),
        ],

        'iyzico' => [
            'driver' => App\Billing\Gateways\IyzicoGateway::class,
            'api_key' => env('IYZICO_API_KEY'),
            'secret_key' => env('IYZICO_SECRET_KEY'),
            // Sandbox: https://sandbox-api.iyzipay.com
            'api_base' => env('IYZICO_API_BASE', 'https://api.iyzipay.com'),
            'timeout' => (int) env('IYZICO_TIMEOUT', 20),
        ],

        'paytr' => [
            'driver' => App\Billing\Gateways\PayTRGateway::class,
            'merchant_id' => env('PAYTR_MERCHANT_ID'),
            'merchant_key' => env('PAYTR_MERCHANT_KEY'),
            'merchant_salt' => env('PAYTR_MERCHANT_SALT'),
            'api_base' => env('PAYTR_API_BASE', 'https://www.paytr.com'),
            'test_mode' => (bool) env('PAYTR_TEST_MODE', false),
            'timeout' => (int) env('PAYTR_TIMEOUT', 20),
        ],
    ],
];
```

`.env.example` keys: `BILLING_GATEWAY`, `BILLING_CURRENCY`, `BILLING_FREE_PLAN`,
`BILLING_TAX_RATE`, `BILLING_INVOICE_PREFIX`, `STRIPE_SECRET_KEY`,
`STRIPE_WEBHOOK_SECRET`, `IYZICO_API_KEY`, `IYZICO_SECRET_KEY`,
`PAYTR_MERCHANT_ID`, `PAYTR_MERCHANT_KEY`, `PAYTR_MERCHANT_SALT`,
`PAYTR_TEST_MODE`.

## 2. Routes (`routes/api.php`)

The webhook route must be **outside** `auth:sanctum` (the signature is the
authentication) and outside CSRF. `tests/Feature/Billing/BillingTestCase.php`
registers exactly this map, so keep the two in step.

```php
use App\Http\Controllers\Api\V1\Billing\{
    CheckoutController, CouponController, InvoiceController,
    PlanController, SubscriptionController, WebhookController
};

Route::prefix('v1')->group(function () {
    Route::get('billing/plans', [PlanController::class, 'index']);
    Route::get('billing/plans/{code}', [PlanController::class, 'show']);

    // Public: verified by gateway signature, rate limited because gateways retry.
    Route::post('billing/webhooks/{gateway}', [WebhookController::class, 'handle'])
        ->middleware('throttle:webhooks');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('billing/subscription', [SubscriptionController::class, 'show']);
        Route::post('billing/subscription/cancel', [SubscriptionController::class, 'cancel']);
        Route::post('billing/subscription/resume', [SubscriptionController::class, 'resume']);
        Route::post('billing/subscription/change-plan', [SubscriptionController::class, 'changePlan']);
        Route::post('billing/checkout', [CheckoutController::class, 'store']);
        Route::post('billing/coupons/apply', [CouponController::class, 'store']);
        Route::get('billing/invoices', [InvoiceController::class, 'index']);
        Route::get('billing/invoices/{number}', [InvoiceController::class, 'show']);
    });
});
```

If a `VerifyCsrfToken`/`validateCsrfTokens` exception list is introduced, add
`api/v1/billing/webhooks/*`.

## 3. Scheduler (`routes/console.php`)

A missed webhook must not leave someone entitled to something they stopped
paying for, so lapsed periods are closed locally and gateway-held subscriptions
are re-read:

```php
use App\Models\Subscription;
use App\Services\Billing\SubscriptionService;

Schedule::call(fn (SubscriptionService $s) => $s->expireLapsed())->hourly();

Schedule::call(function (SubscriptionService $s) {
    Subscription::whereNotNull('gateway_subscription_id')
        ->whereIn('status', ['active', 'trialing', 'past_due'])
        ->where('current_period_end', '<=', now()->addDay())
        ->each(fn ($subscription) => $s->reconcile($subscription));
})->dailyAt('03:20');
```

## 4. Seeding

`database/seeders/PlanSeeder.php` is idempotent (`updateOrCreate`). Add it to
`DatabaseSeeder::run()`:

```php
$this->call(PlanSeeder::class);
```

---

## Using the entitlement layer from other phases

`App\Services\Billing\EntitlementService` is the only place feature access is
decided. Ask it before doing paid work, and spend after (or refund if the work
then failed):

```php
public function __construct(private EntitlementService $entitlements) {}

if (! $this->entitlements->consume($userId, 'ai_messages')) {
    return ApiResponse::error('quota_exceeded', 'Your daily allowance is used up.', 402);
}
```

Features: `ai_messages`, `speech_minutes`, `generated_media`, `exam_prep`,
`premium_tutor` (`EntitlementService::FEATURES`).

* `allows($userId, $feature)` — enabled and quota left.
* `remaining($userId, $feature)` — `null` = unlimited, `0` = blocked.
* `consume($userId, $feature, $amount = 1)` — atomic; `false` records nothing.
* `refund($userId, $feature, $amount)` — hand quota back after a failure.
* `snapshot($userId)` — the whole picture, as the subscription endpoint returns it.

Counters reset on calendar boundaries per `plan_entitlements.limit_period`
(`day` / `week` / `month` / `total`). `past_due` carries no entitlements; a
lifetime plan has a null `current_period_end` and never lapses.

## Gateway notes worth knowing before go-live

* **Stripe** — fully covered: checkout, cancel (immediate + period end), resume,
  plan swap, refund, read, webhook signature (`t=…,v1=…`, 5 min tolerance).
* **iyzico** — subscriptions bill against a *pricing plan reference code* created
  in the merchant panel; store it in `plan_prices.gateway_price_id` with
  `gateway = 'iyzico'`, otherwise checkout fails with `missing_pricing_plan`.
  There is no inline price and no "cancel at period end": cancelling stops
  renewal and we hold the period locally.
  The v3 notification signature is verified as
  `base64(hmac_sha256(secretKey + iyziEventType + iyziReferenceCode + subscriptionReferenceCode + status, secretKey))`
  in `IyzicoGateway::webhookSignature()`. **Confirm the field order against the
  merchant panel's current notification docs before enabling iyzico webhooks** —
  if iyzico changes it, every delivery is rejected (and recorded), never
  silently accepted.
* **PayTR** — iFrame flow. `merchant_oid` is our payment attempt's
  `idempotency_key`, which is also the webhook dedupe key. PayTR has no
  subscription read, resume or plan-swap API; those return
  `unsupported_operation` rather than a fabricated success. The callback must be
  answered with the literal body `OK` — `WebhookController` already does.

## Tests

`tests/Feature/Billing` runs on MariaDB (row locks and duplicate-key handling
are load-bearing) inside a transaction per test, so it neither migrates nor
leaves data behind. If the suite's DB connection is ever renamed away from
`mysql`, update `BillingTestCase::setUp()`.
