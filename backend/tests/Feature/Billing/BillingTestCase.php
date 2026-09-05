<?php

namespace Tests\Feature\Billing;

use App\Billing\BillingConfig;
use App\Http\Controllers\Api\V1\Billing\CheckoutController;
use App\Http\Controllers\Api\V1\Billing\CouponController;
use App\Http\Controllers\Api\V1\Billing\InvoiceController;
use App\Http\Controllers\Api\V1\Billing\PlanController;
use App\Http\Controllers\Api\V1\Billing\SubscriptionController;
use App\Http\Controllers\Api\V1\Billing\WebhookController;
use App\Models\Plan;
use App\Models\PlanEntitlement;
use App\Models\PlanPrice;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Billing runs against MariaDB: the layer leans on row locks and on duplicate
 * key violations for its concurrency guarantees, neither of which behaves the
 * same on the sqlite connection phpunit.xml selects. Each test runs inside a
 * transaction and rolls back, so the shared development data is left alone.
 */
abstract class BillingTestCase extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        $_ENV['DB_CONNECTION'] = 'mysql';
        $_SERVER['DB_CONNECTION'] = 'mysql';
        putenv('DB_CONNECTION=mysql');

        parent::setUp();

        config(['billing' => BillingConfig::defaults()]);
        $this->registerRoutes();
    }

    /**
     * The billing routes live in routes/api.php once the parent wires them
     * (see app/Services/Billing/INTEGRATION.md). Registering the same map here
     * keeps the HTTP tests honest without editing a file this phase does not own.
     */
    protected function registerRoutes(): void
    {
        Route::middleware('api')->prefix('api/v1')->group(function () {
            Route::get('billing/plans', [PlanController::class, 'index']);
            Route::get('billing/plans/{code}', [PlanController::class, 'show']);
            Route::post('billing/webhooks/{gateway}', [WebhookController::class, 'handle']);

            Route::middleware('auth:sanctum')->group(function () {
                Route::get('billing/subscription', [SubscriptionController::class, 'show']);
                Route::post('billing/subscription/cancel', [SubscriptionController::class, 'cancel']);
                Route::post('billing/subscription/resume', [SubscriptionController::class, 'resume']);
                Route::post('billing/subscription/change-plan', [SubscriptionController::class, 'changePlan']);
                Route::post('billing/checkout', [CheckoutController::class, 'store']);
                Route::get('billing/invoices', [InvoiceController::class, 'index']);
                Route::post('billing/coupons/apply', [CouponController::class, 'store']);
            });
        });
    }

    protected function user(): User
    {
        return User::factory()->create();
    }

    /**
     * @param  array<string, array{0: bool, 1: ?int, 2: ?string}>  $entitlements
     */
    protected function plan(array $entitlements = [], array $attributes = [], int $amount = 24900, string $currency = 'TRY'): Plan
    {
        $plan = Plan::create([
            'code' => 'test-'.Str::lower(Str::random(10)),
            'name' => 'Test Plan',
            'interval' => 'monthly',
            'interval_count' => 1,
            'trial_days' => 0,
            'position' => 10,
            'is_active' => true,
            'is_public' => true,
        ] + $attributes);

        PlanPrice::create([
            'plan_id' => $plan->id,
            'currency' => $currency,
            'amount' => $amount,
            'is_active' => true,
        ]);

        foreach ($entitlements as $feature => $rule) {
            PlanEntitlement::create([
                'plan_id' => $plan->id,
                'feature' => $feature,
                'is_enabled' => $rule[0],
                'limit_value' => $rule[1],
                'limit_period' => $rule[2],
            ]);
        }

        return $plan->refresh();
    }

    /** Point the free-tier fallback at a plan this test owns. */
    protected function useAsFreePlan(Plan $plan): void
    {
        config(['billing.free_plan' => $plan->code]);
    }
}
