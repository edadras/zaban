<?php

namespace App\Billing\Support;

/**
 * A webhook after signature verification.
 *
 * A rejected event still becomes a WebhookEvent so the attempt can be recorded:
 * a stream of unverified calls is exactly the signal you want persisted.
 */
final class WebhookEvent
{
    public function __construct(
        public string $gateway,
        public bool $verified,
        public ?string $eventId = null,
        public string $type = 'unknown',
        public array $payload = [],
        public ?string $error = null,
    ) {}

    public static function verified(string $gateway, ?string $eventId, string $type, array $payload): self
    {
        return new self($gateway, true, $eventId, $type, $payload);
    }

    public static function rejected(string $gateway, string $reason, array $payload = [], string $type = 'unknown', ?string $eventId = null): self
    {
        return new self($gateway, false, $eventId, $type, $payload, $reason);
    }
}
