<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\AI\ProviderRegistry;
use App\Http\Controllers\Api\V1\ApiController;
use App\Models\AiProvider;
use App\Models\AiRequest;
use App\Models\AiUsageLimit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** AI cost, reliability and limit administration (spec 43, 44). */
class AiUsageController extends ApiController
{
    public function __construct(private ProviderRegistry $registry) {}

    public function overview(Request $request)
    {
        $days = min(180, max(1, $request->integer('days', 30)));
        $since = now()->subDays($days)->toDateString();

        $byFeature = DB::table('ai_usage')
            ->where('usage_date', '>=', $since)
            ->select('feature',
                DB::raw('SUM(request_count) requests'),
                DB::raw('SUM(estimated_cost) cost'),
                DB::raw('SUM(credits_used) credits'),
                DB::raw('SUM(input_tokens + output_tokens) tokens'))
            ->groupBy('feature')->orderByDesc('cost')->get();

        $status = DB::table('ai_requests')
            ->where('created_at', '>=', now()->subDays($days))
            ->select('status', DB::raw('COUNT(*) n'))->groupBy('status')->pluck('n', 'status');

        $total = max(1, (int) array_sum($status->toArray()));
        $failed = (int) ($status['failed'] ?? 0);

        return $this->ok([
            'window_days' => $days,
            'total_cost' => round((float) DB::table('ai_usage')->where('usage_date', '>=', $since)->sum('estimated_cost'), 4),
            'total_credits' => round((float) DB::table('ai_usage')->where('usage_date', '>=', $since)->sum('credits_used'), 4),
            'total_requests' => $total,
            'failure_rate' => round($failed / $total, 4),
            'by_feature' => $byFeature,
            'by_status' => $status,
            // Proof the reuse policy is doing its job: cache hits are requests
            // we did not pay for.
            'cache' => [
                'served_from_cache' => AiRequest::where('served_from_cache', true)
                    ->where('created_at', '>=', now()->subDays($days))->count(),
                'total_reuse' => (int) DB::table('ai_generations')->sum('reuse_count'),
            ],
        ]);
    }

    public function daily(Request $request)
    {
        $days = min(180, max(7, $request->integer('days', 30)));

        return $this->ok(DB::table('ai_usage')
            ->where('usage_date', '>=', now()->subDays($days)->toDateString())
            ->select('usage_date',
                DB::raw('SUM(request_count) requests'),
                DB::raw('SUM(estimated_cost) cost'),
                DB::raw('SUM(credits_used) credits'))
            ->groupBy('usage_date')->orderBy('usage_date')->get());
    }

    /** Recent failures, so a broken provider is visible rather than silent. */
    public function failures(Request $request)
    {
        $rows = AiRequest::with('provider')
            ->whereIn('status', ['failed', 'timeout'])
            ->orderByDesc('id')
            ->paginate(min(100, $request->integer('per_page', 50)));

        return $this->ok($rows->through(fn (AiRequest $r) => [
            'id' => $r->id,
            'provider' => $r->provider?->code,
            'feature' => $r->feature,
            'status' => $r->status,
            'error' => $r->error,
            'attempt' => $r->attempt,
            'created_at' => $r->created_at?->toIso8601String(),
        ]));
    }

    /** Which providers are actually usable right now. */
    public function providers(Request $request)
    {
        $chains = [];
        foreach (array_keys(config('ai.chains', [])) as $capability) {
            $chains[$capability] = array_map(
                fn ($p) => $p->code(),
                $this->registry->chain($capability),
            );
        }

        $configured = collect(config('ai.providers', []))->map(function ($cfg, $code) {
            $provider = $this->registry->make($code);

            return [
                'code' => $code,
                'driver' => $cfg['driver'] ?? null,
                'resolvable' => $provider !== null,
                'available' => $provider?->isAvailable() ?? false,
                'capabilities' => $provider?->capabilities() ?? [],
            ];
        })->values();

        return $this->ok([
            'chains' => $chains,
            'providers' => $configured,
            'recorded' => AiProvider::select('code', 'is_active', 'priority')->get(),
        ]);
    }

    public function limits(Request $request)
    {
        return $this->ok(AiUsageLimit::with(['plan:id,code,name'])->get());
    }

    public function setLimit(Request $request)
    {
        $data = $request->validate([
            'plan_id' => ['nullable', 'integer', 'exists:plans,id'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'feature' => ['nullable', 'string', 'max:64'],
            'period' => ['required', 'in:day,month'],
            'max_requests' => ['nullable', 'integer', 'min:0'],
            'max_cost' => ['nullable', 'numeric', 'min:0'],
            'max_credits' => ['nullable', 'numeric', 'min:0'],
            'on_exceed' => ['nullable', 'in:block,fallback_model,degrade'],
        ]);

        $limit = AiUsageLimit::updateOrCreate(
            [
                'plan_id' => $data['plan_id'] ?? null,
                'user_id' => $data['user_id'] ?? null,
                'feature' => $data['feature'] ?? null,
                'period' => $data['period'],
            ],
            $data + ['on_exceed' => $data['on_exceed'] ?? 'block'],
        );

        return $this->ok($limit);
    }
}
