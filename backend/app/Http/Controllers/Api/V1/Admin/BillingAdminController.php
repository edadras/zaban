<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Models\AuditLog;
use App\Models\ManualPaymentSubmission;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use App\Models\User;
use App\Services\Billing\ManualTransferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BillingAdminController extends ApiController
{
    public function __construct(private ManualTransferService $transfers) {}

    /** Dashboard figures: users, revenue, pending Rial receipts. */
    public function overview(Request $request)
    {
        $days = min(90, max(7, $request->integer('days', 30)));
        $since = now()->subDays($days)->startOfDay();

        $irrRevenue = (int) SubscriptionTransaction::query()
            ->where('status', 'succeeded')
            ->where('currency', 'IRR')
            ->where('processed_at', '>=', $since)
            ->sum('amount');

        $tryRevenue = (int) SubscriptionTransaction::query()
            ->where('status', 'succeeded')
            ->where('currency', 'TRY')
            ->where('processed_at', '>=', $since)
            ->sum('amount');

        $usdRevenue = (int) SubscriptionTransaction::query()
            ->where('status', 'succeeded')
            ->where('currency', 'USD')
            ->where('processed_at', '>=', $since)
            ->sum('amount');

        $daily = SubscriptionTransaction::query()
            ->selectRaw('DATE(processed_at) as day, currency, SUM(amount) as total, COUNT(*) as cnt')
            ->where('status', 'succeeded')
            ->where('processed_at', '>=', $since)
            ->groupBy('day', 'currency')
            ->orderBy('day')
            ->get()
            ->map(fn ($r) => [
                'day' => $r->day,
                'currency' => $r->currency,
                'total' => (int) $r->total,
                'count' => (int) $r->cnt,
            ]);

        $activeSubs = Subscription::whereIn('status', ['active', 'trialing'])->count();
        $byPlan = Subscription::query()
            ->join('plans', 'plans.id', '=', 'subscriptions.plan_id')
            ->whereIn('subscriptions.status', ['active', 'trialing'])
            ->selectRaw('plans.code, plans.name, COUNT(*) as cnt')
            ->groupBy('plans.code', 'plans.name')
            ->orderByDesc('cnt')
            ->get()
            ->map(fn ($r) => [
                'code' => $r->code,
                'name' => $r->name,
                'count' => (int) $r->cnt,
            ]);

        return $this->ok([
            'window_days' => $days,
            'users' => [
                'total' => User::count(),
                'learners' => User::where('role', 'learner')->count(),
                'active_7d' => User::where('last_active_at', '>=', now()->subDays(7))->count(),
                'new_in_window' => User::where('created_at', '>=', $since)->count(),
            ],
            'subscriptions' => [
                'active' => $activeSubs,
                'by_plan' => $byPlan,
            ],
            'revenue' => [
                'irr' => $irrRevenue,
                'irr_display' => number_format($irrRevenue).' IRR',
                'try_minor' => $tryRevenue,
                'try_display' => number_format($tryRevenue / 100, 2).' TRY',
                'usd_minor' => $usdRevenue,
                'usd_display' => number_format($usdRevenue / 100, 2).' USD',
                'succeeded_count' => SubscriptionTransaction::where('status', 'succeeded')
                    ->where('processed_at', '>=', $since)
                    ->count(),
            ],
            'manual_payments' => [
                'pending_review' => ManualPaymentSubmission::where('status', ManualPaymentSubmission::STATUS_PENDING_REVIEW)->count(),
                'pending_transfer' => ManualPaymentSubmission::where('status', ManualPaymentSubmission::STATUS_PENDING_TRANSFER)->count(),
                'approved_in_window' => ManualPaymentSubmission::where('status', ManualPaymentSubmission::STATUS_APPROVED)
                    ->where('reviewed_at', '>=', $since)
                    ->count(),
            ],
            'daily' => $daily,
            'learning' => [
                'sessions_in_window' => DB::table('learning_sessions')->where('created_at', '>=', $since)->count(),
                'exercise_attempts_in_window' => DB::table('exercise_attempts')->where('created_at', '>=', $since)->count(),
            ],
        ]);
    }

    public function manualPayments(Request $request)
    {
        $status = $request->string('status')->toString();
        $query = ManualPaymentSubmission::with(['user', 'plan', 'reviewer'])
            ->orderByDesc('id');

        if ($status !== '') {
            $query->where('status', $status);
        }

        $page = $query->paginate(min(100, $request->integer('per_page', 25)));

        return $this->ok($page->through(
            fn (ManualPaymentSubmission $s) => $this->transfers->present($s)
        ));
    }

    public function approve(Request $request, ManualPaymentSubmission $submission)
    {
        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $updated = $this->transfers->approve($submission, $request->user(), $data['note'] ?? null);
        } catch (\RuntimeException $e) {
            return $this->fail('approve_failed', $e->getMessage(), 422);
        }

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'manual_payment.approve',
            'auditable_type' => ManualPaymentSubmission::class,
            'auditable_id' => $updated->id,
            'before' => ['status' => ManualPaymentSubmission::STATUS_PENDING_REVIEW],
            'after' => ['status' => $updated->status, 'plan' => $updated->plan?->code],
            'ip_address' => $request->ip(),
        ]);

        return $this->ok($this->transfers->present($updated));
    }

    public function reject(Request $request, ManualPaymentSubmission $submission)
    {
        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $updated = $this->transfers->reject(
                $submission,
                $request->user(),
                $data['note'] ?? 'Receipt rejected.',
            );
        } catch (\RuntimeException $e) {
            return $this->fail('reject_failed', $e->getMessage(), 422);
        }

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'manual_payment.reject',
            'auditable_type' => ManualPaymentSubmission::class,
            'auditable_id' => $updated->id,
            'before' => null,
            'after' => ['status' => $updated->status, 'note' => $updated->admin_note],
            'ip_address' => $request->ip(),
        ]);

        return $this->ok($this->transfers->present($updated));
    }

    public function receipt(Request $request, ManualPaymentSubmission $submission): StreamedResponse|JsonResponse
    {
        if (! $submission->receipt_path || ! Storage::disk(ManualTransferService::DISK)->exists($submission->receipt_path)) {
            return $this->fail('receipt_missing', 'No receipt on file.', 404);
        }

        $mime = Storage::disk(ManualTransferService::DISK)->mimeType($submission->receipt_path) ?: 'application/octet-stream';
        $name = $submission->receipt_original_name ?: basename($submission->receipt_path);

        return Storage::disk(ManualTransferService::DISK)->download(
            $submission->receipt_path,
            $name,
            ['Content-Type' => $mime],
        );
    }

    public function rialSettings(Request $request)
    {
        return $this->ok($this->transfers->settings());
    }

    public function updateRialSettings(Request $request)
    {
        $data = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'card_number' => ['sometimes', 'string', 'max:64'],
            'card_holder' => ['sometimes', 'string', 'max:120'],
            'bank_name' => ['sometimes', 'string', 'max:120'],
            'note' => ['sometimes', 'string', 'max:2000'],
            'try_to_irr_rate' => ['sometimes', 'numeric', 'min:1', 'max:100000000'],
        ]);

        $updated = $this->transfers->updateSettings($data);

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'billing.rial_settings.update',
            'auditable_type' => 'billing.rial_settings',
            'auditable_id' => 0,
            'before' => null,
            'after' => [
                'card_holder' => $updated['card_holder'],
                'bank_name' => $updated['bank_name'],
                'try_to_irr_rate' => $updated['try_to_irr_rate'],
                'enabled' => $updated['enabled'],
            ],
            'ip_address' => $request->ip(),
        ]);

        return $this->ok($updated);
    }
}
