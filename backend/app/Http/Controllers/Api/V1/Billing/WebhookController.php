<?php

namespace App\Http\Controllers\Api\V1\Billing;

use App\Http\Controllers\Api\V1\ApiController;
use App\Services\Billing\WebhookService;
use Illuminate\Http\Request;

/**
 * Public endpoint: authentication is the signature, so this route must stay
 * outside auth middleware and outside CSRF.
 */
class WebhookController extends ApiController
{
    public function __construct(private WebhookService $webhooks) {}

    public function handle(Request $request, string $gateway)
    {
        $outcome = $this->webhooks->handle($gateway, $request->getContent(), $request->headers->all());

        return match ($outcome['status']) {
            // 400 tells the gateway the delivery was bad; it will not be retried
            // into a loop, and the rejection is already on record.
            'rejected' => $this->fail('invalid_signature', $outcome['message'] ?? 'Signature verification failed.', 400),
            'unknown_gateway' => $this->fail('unknown_gateway', $outcome['message'] ?? 'Unknown gateway.', 404),
            // A failed handler must return 5xx so the gateway retries it.
            'failed' => $this->fail('webhook_failed', 'The event could not be processed.', 500),
            default => $this->acknowledge($gateway, $outcome['status']),
        };
    }

    private function acknowledge(string $gateway, string $status)
    {
        // PayTR requires the literal body "OK"; anything else makes it retry.
        if ($gateway === 'paytr') {
            return response('OK', 200)->header('Content-Type', 'text/plain');
        }

        return $this->ok(['status' => $status]);
    }
}
