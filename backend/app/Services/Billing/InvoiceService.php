<?php

namespace App\Services\Billing;

use App\Billing\BillingConfig;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Issues invoices with a gapless per-year sequence.
 *
 * The number is allocated and the row inserted inside one transaction: the
 * SELECT ... FOR UPDATE holds the tail of the sequence (and, on InnoDB, the gap
 * after it) until the insert commits, so two concurrent issuers serialise
 * instead of racing. The unique index on `number` is the backstop, and a
 * duplicate simply retries with the next value.
 */
class InvoiceService
{
    private const MAX_ATTEMPTS = 5;

    public function issueForTransaction(
        SubscriptionTransaction $transaction,
        ?Subscription $subscription = null,
        int $discountTotal = 0,
        array $billingDetails = [],
    ): Invoice {
        $existing = Invoice::where('subscription_transaction_id', $transaction->id)->first();
        if ($existing) {
            return $existing;   // webhooks are retried; an invoice is issued once
        }

        $paid = $transaction->status === 'succeeded';
        $total = (int) $transaction->amount;
        $taxRate = BillingConfig::taxRate();
        // Prices are gross, so tax is the portion already inside the total.
        $tax = $taxRate > 0 ? (int) round($total - ($total / (1 + $taxRate))) : 0;

        return $this->create([
            'user_id' => $transaction->user_id,
            'subscription_id' => $subscription?->id ?? $transaction->subscription_id,
            'subscription_transaction_id' => $transaction->id,
            'status' => $paid ? 'paid' : 'open',
            'subtotal' => $total + $discountTotal,
            'discount_total' => $discountTotal,
            'tax_total' => $tax,
            'total' => $total,
            'currency' => strtoupper((string) $transaction->currency),
            'billing_details' => $billingDetails ?: $this->billingDetailsFor((int) $transaction->user_id),
            'issued_at' => now(),
            'paid_at' => $paid ? ($transaction->processed_at ?? now()) : null,
        ]);
    }

    /** @param  array<string, mixed>  $attributes  everything except `number` */
    public function create(array $attributes): Invoice
    {
        $prefix = $this->prefix();

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                return DB::transaction(function () use ($attributes, $prefix) {
                    $number = $this->nextNumber($prefix);

                    return Invoice::create($attributes + ['number' => $number]);
                });
            } catch (QueryException $e) {
                $duplicate = (int) ($e->errorInfo[1] ?? 0) === 1062 || str_contains($e->getMessage(), 'Duplicate entry');
                if (! $duplicate || $attempt === self::MAX_ATTEMPTS) {
                    throw $e;
                }
            }
        }

        throw new \RuntimeException('Could not allocate an invoice number.');
    }

    /** Must run inside the same transaction as the insert that consumes it. */
    private function nextNumber(string $prefix): string
    {
        $last = DB::table('invoices')
            ->where('number', 'like', $prefix.'%')
            ->orderByDesc('number')
            ->lockForUpdate()
            ->value('number');

        $sequence = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $sequence, BillingConfig::invoicePadding(), '0', STR_PAD_LEFT);
    }

    private function prefix(): string
    {
        return BillingConfig::invoicePrefix().'-'.now()->format('Y').'-';
    }

    private function billingDetailsFor(int $userId): array
    {
        $user = User::find($userId);

        return $user ? ['name' => $user->name, 'email' => $user->email] : [];
    }
}
