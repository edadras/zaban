<?php

namespace App\Services\Billing;

use App\Billing\BillingConfig;
use App\Billing\Contracts\PaymentGatewayInterface;
use App\Billing\Gateways\IyzicoGateway;
use App\Billing\Gateways\PayTRGateway;
use App\Billing\Gateways\StripeGateway;

/**
 * Builds gateway adapters from config.
 *
 * Adapters take scalar credentials, so they are constructed here rather than
 * auto-resolved; adding a gateway means a driver class plus a config block.
 */
class GatewayManager
{
    /** @var array<string, PaymentGatewayInterface|null> */
    private array $resolved = [];

    public function make(?string $code = null): ?PaymentGatewayInterface
    {
        $code = $code ?: BillingConfig::defaultGateway();

        if (array_key_exists($code, $this->resolved)) {
            return $this->resolved[$code];
        }

        $config = BillingConfig::gateway($code);
        $driver = $config['driver'] ?? null;

        return $this->resolved[$code] = match ($driver) {
            StripeGateway::class => new StripeGateway(
                secretKey: $config['secret_key'] ?? null,
                webhookSecret: $config['webhook_secret'] ?? null,
                apiBase: $config['api_base'] ?? 'https://api.stripe.com',
                apiVersion: $config['api_version'] ?? '2024-06-20',
                timeout: (int) ($config['timeout'] ?? 20),
            ),
            IyzicoGateway::class => new IyzicoGateway(
                apiKey: $config['api_key'] ?? null,
                secretKey: $config['secret_key'] ?? null,
                apiBase: $config['api_base'] ?? 'https://api.iyzipay.com',
                timeout: (int) ($config['timeout'] ?? 20),
            ),
            PayTRGateway::class => new PayTRGateway(
                merchantId: $config['merchant_id'] ?? null,
                merchantKey: $config['merchant_key'] ?? null,
                merchantSalt: $config['merchant_salt'] ?? null,
                apiBase: $config['api_base'] ?? 'https://www.paytr.com',
                testMode: (bool) ($config['test_mode'] ?? false),
                timeout: (int) ($config['timeout'] ?? 20),
            ),
            default => null,
        };
    }

    /** Overrides the adapter for a code - used by tests and by webhook replay tooling. */
    public function extend(string $code, PaymentGatewayInterface $gateway): void
    {
        $this->resolved[$code] = $gateway;
    }

    /** @return string[] codes that are configured well enough to be called */
    public function availableCodes(): array
    {
        $codes = [];
        foreach (array_keys(BillingConfig::gateways()) as $code) {
            if ($this->make($code)?->isAvailable()) {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    public function isKnown(string $code): bool
    {
        return array_key_exists($code, BillingConfig::gateways());
    }
}
