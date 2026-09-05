<?php

/**
 * Payment gateways.
 *
 * Adding a provider is a driver class plus a block here - no service, controller
 * or webhook change. With no credentials set, every gateway correctly reports
 * itself unavailable and checkout fails with `gateway_unavailable`; that is the
 * right behaviour for an unconfigured install rather than a fault.
 */
return [
    'default' => env('BILLING_GATEWAY', 'iyzico'),
    'currency' => env('BILLING_CURRENCY', 'TRY'),

    // Plan every user falls back to with no paid subscription; must match a
    // plans.code row (PlanSeeder creates `free`).
    'free_plan' => env('BILLING_FREE_PLAN', 'free'),

    // Portion of a gross price that is tax, for the invoice breakdown.
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
