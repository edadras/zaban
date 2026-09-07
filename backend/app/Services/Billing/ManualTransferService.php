<?php

namespace App\Services\Billing;

use App\Models\AppSetting;
use App\Models\ManualPaymentSubmission;
use App\Models\PaymentAttempt;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\SubscriptionTransaction;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Card-to-card (Rial) checkout: the learner transfers IRR, uploads a receipt,
 * and an admin unlocks the plan after verifying the deposit.
 */
class ManualTransferService
{
    public const GATEWAY = 'manual_irr';

    public const DISK = 'local';

    public function __construct(
        private SubscriptionService $subscriptions,
        private InvoiceService $invoices,
    ) {}

    /** Bank details + live TRY→IRR quote for the plans screen. */
    public function instructions(?Plan $plan = null): array
    {
        $cfg = $this->config();
        $tryPrice = $plan ? $this->tryPriceFor($plan) : null;
        $quote = $tryPrice ? $this->quote($tryPrice) : null;

        return [
            'enabled' => (bool) ($cfg['enabled'] ?? false),
            'method' => 'card_transfer',
            'currency' => 'IRR',
            'source_currency' => 'TRY',
            'card_number' => (string) ($cfg['card_number'] ?? ''),
            'card_holder' => (string) ($cfg['card_holder'] ?? ''),
            'bank_name' => (string) ($cfg['bank_name'] ?? ''),
            'note' => (string) ($cfg['note'] ?? ''),
            'fx_rate' => $this->fxRate(),
            'fx_label' => '1 TRY = '.$this->formatIrr($this->fxRate()).' IRR',
            'plan' => $plan ? [
                'code' => $plan->code,
                'name' => $plan->name,
                'interval' => $plan->interval,
            ] : null,
            'quote' => $quote,
        ];
    }

    public function create(User $user, Plan $plan, ?string $payerName = null): ManualPaymentSubmission
    {
        if (! ($this->config()['enabled'] ?? false)) {
            throw new \RuntimeException('Rial card transfer is not enabled.');
        }

        $price = $this->tryPriceFor($plan);
        if (! $price || (int) $price->amount <= 0) {
            throw new \RuntimeException('This plan has no TRY price to convert.');
        }

        $quote = $this->quote($price);

        $open = ManualPaymentSubmission::where('user_id', $user->id)
            ->where('plan_id', $plan->id)
            ->whereIn('status', [
                ManualPaymentSubmission::STATUS_PENDING_TRANSFER,
                ManualPaymentSubmission::STATUS_PENDING_REVIEW,
            ])
            ->first();
        if ($open) {
            return $open->load(['plan', 'planPrice']);
        }

        $attempt = PaymentAttempt::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'plan_price_id' => $price->id,
            'gateway' => self::GATEWAY,
            'idempotency_key' => 'manual_'.Str::uuid()->toString(),
            'status' => 'initiated',
            'amount' => $quote['amount_irr'],
            'currency' => 'IRR',
            'metadata' => [
                'plan_code' => $plan->code,
                'amount_try' => $quote['amount_try'],
                'fx_rate' => $quote['fx_rate'],
                'method' => 'card_transfer',
            ],
        ]);

        return ManualPaymentSubmission::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'plan_price_id' => $price->id,
            'payment_attempt_id' => $attempt->id,
            'status' => ManualPaymentSubmission::STATUS_PENDING_TRANSFER,
            'amount_try' => $quote['amount_try'],
            'amount_irr' => $quote['amount_irr'],
            'fx_rate' => $quote['fx_rate'],
            'payer_name' => $payerName,
            'metadata' => [
                'card_number' => $this->config()['card_number'] ?? null,
                'card_holder' => $this->config()['card_holder'] ?? null,
            ],
        ])->load(['plan', 'planPrice']);
    }

    public function attachReceipt(
        ManualPaymentSubmission $submission,
        UploadedFile $file,
        ?string $payerName = null,
    ): ManualPaymentSubmission {
        if (! in_array($submission->status, [
            ManualPaymentSubmission::STATUS_PENDING_TRANSFER,
            ManualPaymentSubmission::STATUS_PENDING_REVIEW,
            ManualPaymentSubmission::STATUS_REJECTED,
        ], true)) {
            throw new \RuntimeException('This payment can no longer accept a receipt.');
        }

        $dir = 'manual-payments/'.$submission->user_id;
        $path = $file->store($dir, self::DISK);

        if ($submission->receipt_path) {
            Storage::disk(self::DISK)->delete($submission->receipt_path);
        }

        $submission->update([
            'receipt_path' => $path,
            'receipt_original_name' => $file->getClientOriginalName(),
            'receipt_uploaded_at' => now(),
            'payer_name' => $payerName ?: $submission->payer_name,
            'status' => ManualPaymentSubmission::STATUS_PENDING_REVIEW,
            'admin_note' => null,
            'reviewed_by' => null,
            'reviewed_at' => null,
        ]);

        if ($submission->payment_attempt_id) {
            PaymentAttempt::where('id', $submission->payment_attempt_id)
                ->update(['status' => 'redirected']); // awaiting confirmation
        }

        return $submission->fresh(['plan', 'planPrice', 'user']);
    }

    public function approve(ManualPaymentSubmission $submission, User $admin, ?string $note = null): ManualPaymentSubmission
    {
        if ($submission->status === ManualPaymentSubmission::STATUS_APPROVED) {
            return $submission->load(['plan', 'user']);
        }
        if ($submission->status !== ManualPaymentSubmission::STATUS_PENDING_REVIEW) {
            throw new \RuntimeException('Only submissions with a receipt can be approved.');
        }
        if (! $submission->receipt_path) {
            throw new \RuntimeException('A receipt is required before approval.');
        }

        return DB::transaction(function () use ($submission, $admin, $note) {
            $plan = $submission->plan ?? Plan::findOrFail($submission->plan_id);
            $price = $submission->planPrice;

            $subscription = $this->subscriptions->activate(
                userId: (int) $submission->user_id,
                plan: $plan,
                gatewayCode: self::GATEWAY,
                gatewaySubscriptionId: 'manual-'.$submission->id,
                price: $price,
            );

            $transaction = SubscriptionTransaction::create([
                'subscription_id' => $subscription->id,
                'user_id' => $submission->user_id,
                'gateway' => self::GATEWAY,
                'gateway_transaction_id' => 'manual-'.$submission->id,
                'type' => 'charge',
                'status' => 'succeeded',
                'amount' => $submission->amount_irr,
                'currency' => 'IRR',
                'processed_at' => now(),
                'gateway_payload' => [
                    'manual_payment_id' => $submission->id,
                    'amount_try' => $submission->amount_try,
                    'fx_rate' => $submission->fx_rate,
                    'payment_attempt_id' => $submission->payment_attempt_id,
                ],
            ]);

            $this->invoices->issueForTransaction($transaction, $subscription);

            if ($submission->payment_attempt_id) {
                PaymentAttempt::where('id', $submission->payment_attempt_id)->update([
                    'status' => 'succeeded',
                    'gateway_reference' => 'manual-'.$submission->id,
                ]);
            }

            $submission->update([
                'status' => ManualPaymentSubmission::STATUS_APPROVED,
                'admin_note' => $note,
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
            ]);

            return $submission->fresh(['plan', 'user', 'reviewer']);
        });
    }

    public function reject(ManualPaymentSubmission $submission, User $admin, ?string $note = null): ManualPaymentSubmission
    {
        if ($submission->status === ManualPaymentSubmission::STATUS_APPROVED) {
            throw new \RuntimeException('An approved payment cannot be rejected.');
        }

        $submission->update([
            'status' => ManualPaymentSubmission::STATUS_REJECTED,
            'admin_note' => $note ?: 'Receipt rejected.',
            'reviewed_by' => $admin->id,
            'reviewed_at' => now(),
        ]);

        if ($submission->payment_attempt_id) {
            PaymentAttempt::where('id', $submission->payment_attempt_id)->update([
                'status' => 'failed',
                'failure_reason' => $note ?: 'Receipt rejected by admin.',
            ]);
        }

        return $submission->fresh(['plan', 'user', 'reviewer']);
    }

    /** @return array{amount_try:int,amount_irr:int,fx_rate:float,amount_try_display:string,amount_irr_display:string} */
    public function quote(PlanPrice $price): array
    {
        $amountTry = (int) $price->amount;
        $rate = $this->fxRate();
        $tryMajor = $amountTry / 100;
        $amountIrr = (int) max(1, (int) round($tryMajor * $rate));

        return [
            'amount_try' => $amountTry,
            'amount_irr' => $amountIrr,
            'fx_rate' => $rate,
            'amount_try_display' => number_format($tryMajor, 2).' TRY',
            'amount_irr_display' => $this->formatIrr($amountIrr).' IRR',
        ];
    }

    public function present(ManualPaymentSubmission $s): array
    {
        $instructions = $this->instructions($s->plan);

        return [
            'id' => $s->id,
            'status' => $s->status,
            'plan_code' => $s->plan?->code,
            'plan_name' => $s->plan?->name,
            'amount_try' => $s->amount_try,
            'amount_irr' => $s->amount_irr,
            'amount_try_display' => number_format($s->amount_try / 100, 2).' TRY',
            'amount_irr_display' => $this->formatIrr($s->amount_irr).' IRR',
            'fx_rate' => (float) $s->fx_rate,
            'payer_name' => $s->payer_name,
            'has_receipt' => filled($s->receipt_path),
            'receipt_original_name' => $s->receipt_original_name,
            'receipt_uploaded_at' => $s->receipt_uploaded_at?->toIso8601String(),
            'admin_note' => $s->admin_note,
            'reviewed_at' => $s->reviewed_at?->toIso8601String(),
            'created_at' => $s->created_at?->toIso8601String(),
            'user' => $s->relationLoaded('user') || $s->user
                ? [
                    'id' => $s->user?->id,
                    'name' => $s->user?->name,
                    'email' => $s->user?->email,
                ]
                : null,
            'instructions' => [
                'card_number' => $instructions['card_number'],
                'card_holder' => $instructions['card_holder'],
                'bank_name' => $instructions['bank_name'],
                'note' => $instructions['note'],
                'fx_label' => $instructions['fx_label'],
            ],
        ];
    }

    /** Operator-editable Rial transfer settings (DB overrides env defaults). */
    public function settings(): array
    {
        $cfg = $this->config();

        return [
            'enabled' => (bool) ($cfg['enabled'] ?? false),
            'card_number' => (string) ($cfg['card_number'] ?? ''),
            'card_holder' => (string) ($cfg['card_holder'] ?? ''),
            'bank_name' => (string) ($cfg['bank_name'] ?? ''),
            'note' => (string) ($cfg['note'] ?? ''),
            'try_to_irr_rate' => $this->fxRate(),
            'fx_label' => '1 TRY = '.$this->formatIrr($this->fxRate()).' IRR',
        ];
    }

    /** @param  array{enabled?:bool,card_number?:string,card_holder?:string,bank_name?:string,note?:string,try_to_irr_rate?:float|int|string}  $data */
    public function updateSettings(array $data): array
    {
        $map = [
            'enabled' => 'billing.manual.enabled',
            'card_number' => 'billing.manual.card_number',
            'card_holder' => 'billing.manual.card_holder',
            'bank_name' => 'billing.manual.bank_name',
            'note' => 'billing.manual.note',
            'try_to_irr_rate' => 'billing.manual.try_to_irr_rate',
        ];

        foreach ($map as $field => $key) {
            if (! array_key_exists($field, $data)) {
                continue;
            }
            $value = $data[$field];
            if ($field === 'enabled') {
                $value = filter_var($value, FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
            }
            if ($field === 'try_to_irr_rate') {
                $value = (string) max(1, (float) $value);
            }
            AppSetting::putValue($key, $value);
        }

        return $this->settings();
    }

    private function tryPriceFor(Plan $plan): ?PlanPrice
    {
        return PlanPrice::where('plan_id', $plan->id)
            ->where('currency', 'TRY')
            ->where('is_active', true)
            ->orderByDesc('id')
            ->first();
    }

    private function fxRate(): float
    {
        return max(1.0, (float) ($this->config()['try_to_irr_rate'] ?? 3500));
    }

    private function formatIrr(float|int $amount): string
    {
        return number_format((float) $amount, 0, '.', ',');
    }

    /**
     * Env/config defaults, overridden by app_settings rows when present.
     *
     * @return array{enabled:bool,card_number:string,card_holder:string,bank_name:string,note:string,try_to_irr_rate:float}
     */
    private function config(): array
    {
        $base = (array) config('billing.manual_transfer', []);

        $enabled = AppSetting::getValue('billing.manual.enabled');
        $card = AppSetting::getValue('billing.manual.card_number');
        $holder = AppSetting::getValue('billing.manual.card_holder');
        $bank = AppSetting::getValue('billing.manual.bank_name');
        $note = AppSetting::getValue('billing.manual.note');
        $rate = AppSetting::getValue('billing.manual.try_to_irr_rate');

        return [
            'enabled' => $enabled === null
                ? (bool) ($base['enabled'] ?? false)
                : filter_var($enabled, FILTER_VALIDATE_BOOLEAN),
            'card_number' => $card !== null ? (string) $card : (string) ($base['card_number'] ?? ''),
            'card_holder' => $holder !== null ? (string) $holder : (string) ($base['card_holder'] ?? ''),
            'bank_name' => $bank !== null ? (string) $bank : (string) ($base['bank_name'] ?? ''),
            'note' => $note !== null ? (string) $note : (string) ($base['note'] ?? ''),
            'try_to_irr_rate' => $rate !== null
                ? (float) $rate
                : (float) ($base['try_to_irr_rate'] ?? 3500),
        ];
    }
}
