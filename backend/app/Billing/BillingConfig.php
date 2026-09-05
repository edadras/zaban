<?php

namespace App\Billing;

/**
 * Reads billing settings from config/billing.php.
 *
 * Until that file is published (see app/Services/Billing/INTEGRATION.md) the
 * defaults here keep the layer functional: every gateway simply reports itself
 * unavailable, which is the correct state for missing credentials.
 */
final class BillingConfig
{
    public static function gateways(): array
    {
        $configured = config('billing.gateways');

        return is_array($configured) ? $configured : self::defaults()['gateways'];
    }

    public static function gateway(string $code): array
    {
        return self::gateways()[$code] ?? [];
    }

    public static function defaultGateway(): string
    {
        return (string) config('billing.default', self::defaults()['default']);
    }

    public static function currency(): string
    {
        return strtoupper((string) config('billing.currency', self::defaults()['currency']));
    }

    public static function invoicePrefix(): string
    {
        return (string) config('billing.invoice.prefix', self::defaults()['invoice']['prefix']);
    }

    public static function invoicePadding(): int
    {
        return (int) config('billing.invoice.padding', self::defaults()['invoice']['padding']);
    }

    /** Plan every user falls back to when they hold no paid subscription. */
    public static function freePlanCode(): string
    {
        return (string) config('billing.free_plan', self::defaults()['free_plan']);
    }

    public static function taxRate(): float
    {
        return (float) config('billing.tax_rate', self::defaults()['tax_rate']);
    }

    public static function defaults(): array
    {
        return [
            'default' => 'stripe',
            'currency' => 'TRY',
            'free_plan' => 'free',
            'tax_rate' => 0.0,
            'invoice' => ['prefix' => 'ZBN', 'padding' => 6],
            'gateways' => [
                'stripe' => [
                    'driver' => Gateways\StripeGateway::class,
                    'secret_key' => null,
                    'webhook_secret' => null,
                    'api_base' => 'https://api.stripe.com',
                    'api_version' => '2024-06-20',
                    'timeout' => 20,
                ],
                'iyzico' => [
                    'driver' => Gateways\IyzicoGateway::class,
                    'api_key' => null,
                    'secret_key' => null,
                    'api_base' => 'https://api.iyzipay.com',
                    'timeout' => 20,
                ],
                'paytr' => [
                    'driver' => Gateways\PayTRGateway::class,
                    'merchant_id' => null,
                    'merchant_key' => null,
                    'merchant_salt' => null,
                    'api_base' => 'https://www.paytr.com',
                    'test_mode' => false,
                    'timeout' => 20,
                ],
            ],
        ];
    }
}
