<?php

namespace App\Http\Controllers\Api\V1\Billing;

use App\Http\Controllers\Api\V1\ApiController;
use App\Models\ManualPaymentSubmission;
use App\Models\Plan;
use App\Services\Billing\ManualTransferService;
use Illuminate\Http\Request;

class ManualTransferController extends ApiController
{
    public function __construct(private ManualTransferService $transfers) {}

    /** Bank card details + TRY→IRR quote for a plan. */
    public function instructions(Request $request)
    {
        $plan = null;
        if ($request->filled('plan_code')) {
            $plan = Plan::where('code', $request->string('plan_code'))
                ->where('is_active', true)
                ->first();
            if (! $plan) {
                return $this->fail('plan_not_found', 'That plan was not found.', 404);
            }
        }

        $payload = $this->transfers->instructions($plan);
        if (! $payload['enabled']) {
            return $this->fail('manual_disabled', 'Rial card transfer is not available right now.', 503);
        }

        return $this->ok($payload);
    }

    /** Start (or resume) a Rial transfer for a paid plan. */
    public function store(Request $request)
    {
        $data = $request->validate([
            'plan_code' => ['required', 'string', 'max:64'],
            'payer_name' => ['nullable', 'string', 'max:120'],
        ]);

        $plan = Plan::where('code', $data['plan_code'])
            ->where('is_active', true)
            ->where('is_public', true)
            ->first();
        if (! $plan || $plan->code === config('billing.free_plan', 'free')) {
            return $this->fail('plan_not_purchasable', 'That plan cannot be bought with a card transfer.', 422);
        }

        try {
            $submission = $this->transfers->create(
                $request->user(),
                $plan,
                $data['payer_name'] ?? $request->user()->name,
            );
        } catch (\RuntimeException $e) {
            return $this->fail('manual_checkout_failed', $e->getMessage(), 422);
        }

        return $this->created([
            'submission' => $this->transfers->present($submission),
            'instructions' => $this->transfers->instructions($plan),
        ]);
    }

    public function mine(Request $request)
    {
        $rows = ManualPaymentSubmission::with('plan')
            ->where('user_id', $request->user()->id)
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(fn (ManualPaymentSubmission $s) => $this->transfers->present($s));

        return $this->ok($rows);
    }

    public function show(Request $request, ManualPaymentSubmission $submission)
    {
        if ((int) $submission->user_id !== (int) $request->user()->id) {
            return $this->fail('forbidden', 'Not your payment.', 403);
        }

        return $this->ok($this->transfers->present($submission->load('plan')));
    }

    public function uploadReceipt(Request $request, ManualPaymentSubmission $submission)
    {
        if ((int) $submission->user_id !== (int) $request->user()->id) {
            return $this->fail('forbidden', 'Not your payment.', 403);
        }

        $request->validate([
            'receipt' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:8192'],
            'payer_name' => ['nullable', 'string', 'max:120'],
        ]);

        try {
            $updated = $this->transfers->attachReceipt(
                $submission,
                $request->file('receipt'),
                $request->input('payer_name'),
            );
        } catch (\RuntimeException $e) {
            return $this->fail('receipt_rejected', $e->getMessage(), 422);
        }

        return $this->ok($this->transfers->present($updated));
    }
}
