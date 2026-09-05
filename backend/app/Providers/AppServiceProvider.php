<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->rateLimits();
    }

    /**
     * Tighter limits where a request costs money or is worth brute-forcing.
     */
    private function rateLimits(): void
    {
        RateLimiter::for('api', fn (Request $r) => Limit::perMinute(120)
            ->by($r->user()?->id ?: $r->ip()));

        RateLimiter::for('auth', fn (Request $r) => [
            Limit::perMinute(5)->by($r->ip()),
            Limit::perMinute(5)->by((string) $r->input('email')),
        ]);

        // Each of these spends provider credit, so they are capped per user.
        RateLimiter::for('ai', fn (Request $r) => Limit::perMinute(20)
            ->by($r->user()?->id ?: $r->ip()));

        RateLimiter::for('speech', fn (Request $r) => Limit::perMinute(12)
            ->by($r->user()?->id ?: $r->ip()));

        // Gateways retry aggressively; the ceiling is high but not unbounded.
        RateLimiter::for('webhooks', fn (Request $r) => Limit::perMinute(300)->by($r->ip()));
    }
}
