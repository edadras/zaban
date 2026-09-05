<?php

namespace App\AI;

use App\AI\Contracts\AiProviderInterface;
use Illuminate\Contracts\Container\Container;

/**
 * Resolves the ordered provider chain for a capability.
 *
 * Order comes from config, so switching vendors or inserting a fallback is a
 * configuration change and never a code change.
 */
class ProviderRegistry
{
    /** @var array<string, AiProviderInterface> */
    private array $resolved = [];

    public function __construct(private Container $container) {}

    /** @return AiProviderInterface[] */
    public function chain(string $capability): array
    {
        $chain = config("ai.chains.{$capability}", []);
        $out = [];
        foreach ($chain as $code) {
            $provider = $this->make($code);
            if ($provider && $provider->isAvailable() && in_array($capability, $provider->capabilities(), true)) {
                $out[] = $provider;
            }
        }

        return $out;
    }

    public function make(string $code): ?AiProviderInterface
    {
        if (isset($this->resolved[$code])) {
            return $this->resolved[$code];
        }
        $class = config("ai.providers.{$code}.driver");
        if (! $class || ! class_exists($class)) {
            return null;
        }

        return $this->resolved[$code] = $this->container->make($class);
    }
}
