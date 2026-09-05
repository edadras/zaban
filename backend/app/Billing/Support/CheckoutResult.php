<?php

namespace App\Billing\Support;

/**
 * Outcome of opening a checkout.
 *
 * Not every gateway hands back a URL: iyzico returns an HTML form fragment the
 * client renders, PayTR returns a token that becomes an iframe source. The
 * caller gets whichever the gateway actually produced, never a fabricated one.
 */
final class CheckoutResult
{
    public function __construct(
        public bool $ok,
        public ?string $reference = null,
        public ?string $redirectUrl = null,
        public ?string $htmlContent = null,
        public ?string $errorCode = null,
        public ?string $error = null,
        public array $raw = [],
    ) {}

    public static function success(
        string $reference,
        ?string $redirectUrl = null,
        ?string $htmlContent = null,
        array $raw = [],
    ): self {
        return new self(true, $reference, $redirectUrl, $htmlContent, null, null, $raw);
    }

    public static function failure(string $code, string $message, array $raw = []): self
    {
        return new self(false, null, null, null, $code, $message, $raw);
    }
}
