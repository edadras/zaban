<?php

namespace App\Billing\Support;

/** Generic yes/no outcome for cancel, resume, refund and plan-change calls. */
final class GatewayResult
{
    public function __construct(
        public bool $ok,
        public ?string $reference = null,
        public ?string $errorCode = null,
        public ?string $error = null,
        public array $raw = [],
    ) {}

    public static function success(?string $reference = null, array $raw = []): self
    {
        return new self(true, $reference, null, null, $raw);
    }

    public static function failure(string $code, string $message, array $raw = []): self
    {
        return new self(false, null, $code, $message, $raw);
    }
}
