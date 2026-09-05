<?php

namespace App\Services\Billing;

use App\Billing\BillingConfig;
use App\Models\EntitlementUsage;
use App\Models\Plan;
use App\Models\PlanEntitlement;
use App\Models\Subscription;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The single source of truth for feature access.
 *
 * Nothing else may decide whether a user can send another AI message or record
 * another minute of speech: the client is never trusted, and callers ask here
 * both before doing the work (allows/remaining) and when spending it (consume).
 *
 * A user with no paid subscription falls back to the free plan's entitlements,
 * so "no subscription" is a plan like any other rather than a special case.
 */
class EntitlementService
{
    /** Features the product bills on (spec section 5). */
    public const FEATURES = [
        'ai_messages',
        'speech_minutes',
        'generated_media',
        'exam_prep',
        'premium_tutor',
    ];

    /** Statuses that carry entitlements. past_due deliberately does not. */
    private const ENTITLED_STATUSES = ['active', 'trialing'];

    /** @var array<string, ?PlanEntitlement> request-scoped memo */
    private array $memo = [];

    public function activeSubscription(int $userId): ?Subscription
    {
        return Subscription::query()
            ->where('user_id', $userId)
            ->whereIn('status', self::ENTITLED_STATUSES)
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->where(function ($q) {
                // A lifetime plan has no period end; a trial is covered by its
                // own clock while the period fields are still empty.
                $q->whereNull('current_period_end')
                    ->orWhere('current_period_end', '>', now())
                    ->orWhere(fn ($t) => $t->where('status', 'trialing')->where('trial_ends_at', '>', now()));
            })
            ->orderByDesc('id')
            ->first();
    }

    public function planFor(int $userId): ?Plan
    {
        $subscription = $this->activeSubscription($userId);
        if ($subscription) {
            return $subscription->plan;
        }

        return Plan::where('code', BillingConfig::freePlanCode())->where('is_active', true)->first();
    }

    public function entitlement(int $userId, string $feature): ?PlanEntitlement
    {
        $key = $userId.'|'.$feature;
        if (array_key_exists($key, $this->memo)) {
            return $this->memo[$key];
        }

        $plan = $this->planFor($userId);

        return $this->memo[$key] = $plan
            ? PlanEntitlement::where('plan_id', $plan->id)->where('feature', $feature)->first()
            : null;
    }

    /** Access is granted only by an enabled entitlement with quota left. */
    public function allows(int $userId, string $feature): bool
    {
        $entitlement = $this->entitlement($userId, $feature);
        if (! $entitlement || ! $entitlement->is_enabled) {
            return false;
        }
        if ($entitlement->limit_value === null) {
            return true;
        }

        return $this->remaining($userId, $feature) > 0;
    }

    /** null means unlimited; 0 means blocked (disabled or exhausted). */
    public function remaining(int $userId, string $feature): ?int
    {
        $entitlement = $this->entitlement($userId, $feature);
        if (! $entitlement || ! $entitlement->is_enabled) {
            return 0;
        }
        if ($entitlement->limit_value === null) {
            return null;
        }

        $usage = $this->currentUsage($userId, $feature, $entitlement);

        return max(0, (int) $entitlement->limit_value - (int) ($usage?->used ?? 0));
    }

    public function used(int $userId, string $feature): int
    {
        $entitlement = $this->entitlement($userId, $feature);
        if (! $entitlement) {
            return 0;
        }

        return (int) ($this->currentUsage($userId, $feature, $entitlement)?->used ?? 0);
    }

    /**
     * Spend quota. Returns false without recording anything when the feature is
     * disabled or the remaining allowance is smaller than the amount asked for,
     * so a caller can treat it as the gate itself.
     */
    public function consume(int $userId, string $feature, int $amount = 1): bool
    {
        if ($amount < 1) {
            return false;
        }

        $entitlement = $this->entitlement($userId, $feature);
        if (! $entitlement || ! $entitlement->is_enabled) {
            return false;
        }

        $period = $this->periodOf($entitlement);
        $periodStart = $this->periodStart($period);

        return DB::transaction(function () use ($userId, $feature, $entitlement, $period, $periodStart, $amount) {
            $usage = $this->lockedUsage($userId, $feature, $period, $periodStart, $entitlement->limit_value);

            // Re-read the plan limit each time so an upgrade mid-period widens
            // the allowance immediately instead of at the next reset.
            if ((int) $usage->limit_value !== (int) $entitlement->limit_value || $entitlement->limit_value === null) {
                $usage->limit_value = $entitlement->limit_value;
            }

            if ($entitlement->limit_value !== null && ($usage->used + $amount) > (int) $entitlement->limit_value) {
                return false;
            }

            $usage->used += $amount;
            $usage->save();

            return true;
        });
    }

    /** Give quota back, e.g. when the work the caller charged for then failed. */
    public function refund(int $userId, string $feature, int $amount = 1): void
    {
        $entitlement = $this->entitlement($userId, $feature);
        if (! $entitlement || $amount < 1) {
            return;
        }

        $period = $this->periodOf($entitlement);
        $periodStart = $this->periodStart($period);

        DB::transaction(function () use ($userId, $feature, $period, $periodStart, $amount, $entitlement) {
            $usage = $this->lockedUsage($userId, $feature, $period, $periodStart, $entitlement->limit_value);
            $usage->used = max(0, $usage->used - $amount);
            $usage->save();
        });
    }

    /** @return array<string, array{enabled: bool, limit: ?int, used: int, remaining: ?int, period: string}> */
    public function snapshot(int $userId): array
    {
        $out = [];
        foreach (self::FEATURES as $feature) {
            $entitlement = $this->entitlement($userId, $feature);
            $out[$feature] = [
                'enabled' => (bool) $entitlement?->is_enabled,
                'limit' => $entitlement?->limit_value !== null ? (int) $entitlement->limit_value : null,
                'used' => $this->used($userId, $feature),
                'remaining' => $this->remaining($userId, $feature),
                'period' => $entitlement ? $this->periodOf($entitlement) : 'total',
            ];
        }

        return $out;
    }

    /** Called after a plan change so the next check re-reads entitlements. */
    public function forget(int $userId): void
    {
        foreach (array_keys($this->memo) as $key) {
            if (str_starts_with($key, $userId.'|')) {
                unset($this->memo[$key]);
            }
        }
    }

    private function currentUsage(int $userId, string $feature, PlanEntitlement $entitlement): ?EntitlementUsage
    {
        $period = $this->periodOf($entitlement);

        return EntitlementUsage::where('user_id', $userId)
            ->where('feature', $feature)
            ->where('period', $period)
            ->whereDate('period_start', $this->periodStart($period))
            ->first();
    }

    private function lockedUsage(int $userId, string $feature, string $period, Carbon $periodStart, ?int $limit): EntitlementUsage
    {
        $query = fn () => EntitlementUsage::where('user_id', $userId)
            ->where('feature', $feature)
            ->where('period', $period)
            ->whereDate('period_start', $periodStart)
            ->lockForUpdate()
            ->first();

        $usage = $query();
        if ($usage) {
            return $usage;
        }

        try {
            return EntitlementUsage::create([
                'user_id' => $userId,
                'feature' => $feature,
                'period' => $period,
                'period_start' => $periodStart->toDateString(),
                'used' => 0,
                'limit_value' => $limit,
            ]);
        } catch (QueryException $e) {
            // Another request created the row between the select and the insert.
            $duplicate = (int) ($e->errorInfo[1] ?? 0) === 1062 || str_contains($e->getMessage(), 'Duplicate entry');
            if (! $duplicate) {
                throw $e;
            }

            return $query() ?? throw $e;
        }
    }

    private function periodOf(PlanEntitlement $entitlement): string
    {
        $period = $entitlement->limit_period;

        return in_array($period, ['day', 'week', 'month', 'total'], true) ? $period : 'total';
    }

    /**
     * Counters reset on calendar boundaries rather than on the billing anchor:
     * the same window then applies to free users, trialists and subscribers, and
     * a mid-period plan change cannot silently hand out a second allowance.
     * `total` uses a fixed epoch so the unique key still holds one row.
     */
    public function periodStart(string $period, ?Carbon $now = null): Carbon
    {
        $now = $now ? $now->copy() : Carbon::now();

        return match ($period) {
            'day' => $now->startOfDay(),
            'week' => $now->startOfWeek(),
            'month' => $now->startOfMonth(),
            default => Carbon::create(1970, 1, 1, 0, 0, 0),
        };
    }
}
