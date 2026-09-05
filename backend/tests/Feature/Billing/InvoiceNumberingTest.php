<?php

namespace Tests\Feature\Billing;

use App\Models\Invoice;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use App\Services\Billing\InvoiceService;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;

class InvoiceNumberingTest extends BillingTestCase
{
    private function invoices(): InvoiceService
    {
        return new InvoiceService;
    }

    private function sequenceOf(string $number): int
    {
        return (int) Str::afterLast($number, '-');
    }

    #[Test]
    public function numbers_are_prefixed_by_year_and_increase_by_one(): void
    {
        $user = $this->user();
        $service = $this->invoices();

        $numbers = [];
        for ($i = 0; $i < 5; $i++) {
            $numbers[] = $service->create([
                'user_id' => $user->id,
                'status' => 'paid',
                'subtotal' => 24900,
                'total' => 24900,
                'currency' => 'TRY',
                'issued_at' => now(),
            ])->number;
        }

        $prefix = 'ZBN-'.now()->format('Y').'-';
        foreach ($numbers as $number) {
            $this->assertStringStartsWith($prefix, $number);
            $this->assertSame(6, strlen(Str::afterLast($number, '-')));
        }

        $sequences = array_map(fn ($n) => $this->sequenceOf($n), $numbers);
        $this->assertSame(range($sequences[0], $sequences[0] + 4), $sequences);
        $this->assertSame($numbers, array_values(array_unique($numbers)));
    }

    #[Test]
    public function a_number_taken_by_another_writer_is_skipped_rather_than_colliding(): void
    {
        $user = $this->user();
        $service = $this->invoices();

        $first = $service->create([
            'user_id' => $user->id, 'status' => 'paid', 'subtotal' => 100,
            'total' => 100, 'currency' => 'TRY', 'issued_at' => now(),
        ]);

        // Simulate a writer that grabbed the next number outside our sequence.
        $prefix = 'ZBN-'.now()->format('Y').'-';
        $stolen = $prefix.str_pad((string) ($this->sequenceOf($first->number) + 1), 6, '0', STR_PAD_LEFT);
        Invoice::create([
            'user_id' => $user->id, 'number' => $stolen, 'status' => 'paid', 'subtotal' => 100,
            'total' => 100, 'currency' => 'TRY', 'issued_at' => now(),
        ]);

        $next = $service->create([
            'user_id' => $user->id, 'status' => 'paid', 'subtotal' => 100,
            'total' => 100, 'currency' => 'TRY', 'issued_at' => now(),
        ]);

        $this->assertSame($this->sequenceOf($stolen) + 1, $this->sequenceOf($next->number));
        $this->assertSame(3, Invoice::where('user_id', $user->id)->count());
    }

    #[Test]
    public function each_year_starts_its_own_sequence(): void
    {
        $user = $this->user();
        // 2037 rather than a rounder future year: issued_at is a TIMESTAMP column.
        $this->travelTo(now()->setYear(2037)->startOfYear()->addDay());

        $number = $this->invoices()->create([
            'user_id' => $user->id, 'status' => 'paid', 'subtotal' => 100,
            'total' => 100, 'currency' => 'TRY', 'issued_at' => now(),
        ])->number;

        $this->assertSame('ZBN-2037-000001', $number);
        $this->travelBack();
    }

    #[Test]
    public function an_invoice_is_issued_once_per_transaction(): void
    {
        $plan = $this->plan();
        $user = $this->user();
        $subscription = Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'gateway' => 'stripe',
            'status' => 'active',
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
        ]);

        $transaction = SubscriptionTransaction::create([
            'subscription_id' => $subscription->id,
            'user_id' => $user->id,
            'gateway' => 'stripe',
            'gateway_transaction_id' => 'pi_'.Str::random(12),
            'type' => 'charge',
            'status' => 'succeeded',
            'amount' => 24900,
            'currency' => 'TRY',
            'processed_at' => now(),
        ]);

        $service = $this->invoices();
        $first = $service->issueForTransaction($transaction, $subscription, 2490);
        $second = $service->issueForTransaction($transaction, $subscription, 2490);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Invoice::where('subscription_transaction_id', $transaction->id)->count());
        $this->assertSame('paid', $first->status);
        $this->assertSame(27390, (int) $first->subtotal);
        $this->assertSame(2490, (int) $first->discount_total);
        $this->assertSame(24900, (int) $first->total);
        $this->assertSame(['name' => $user->name, 'email' => $user->email], $first->billing_details);
    }

    #[Test]
    public function an_unpaid_transaction_produces_an_open_invoice(): void
    {
        $user = $this->user();
        $transaction = SubscriptionTransaction::create([
            'user_id' => $user->id,
            'gateway' => 'stripe',
            'gateway_transaction_id' => 'pi_'.Str::random(12),
            'type' => 'charge',
            'status' => 'failed',
            'amount' => 24900,
            'currency' => 'TRY',
        ]);

        $invoice = $this->invoices()->issueForTransaction($transaction);

        $this->assertSame('open', $invoice->status);
        $this->assertNull($invoice->paid_at);
    }

    #[Test]
    public function tax_is_extracted_from_the_gross_total_when_a_rate_is_configured(): void
    {
        config(['billing.tax_rate' => 0.20]);
        $user = $this->user();
        $transaction = SubscriptionTransaction::create([
            'user_id' => $user->id,
            'gateway' => 'stripe',
            'gateway_transaction_id' => 'pi_'.Str::random(12),
            'type' => 'charge',
            'status' => 'succeeded',
            'amount' => 24000,
            'currency' => 'TRY',
            'processed_at' => now(),
        ]);

        $invoice = $this->invoices()->issueForTransaction($transaction);

        $this->assertSame(24000, (int) $invoice->total);
        $this->assertSame(4000, (int) $invoice->tax_total);
    }
}
