<?php

use App\Models\Subscription;
use App\Services\Billing\SubscriptionService;
use App\Services\Speech\SpeechRetentionService;
use Illuminate\Support\Facades\Schedule;

/*
 * Scheduled work.
 *
 * The billing entries exist because a missed webhook must not leave someone
 * entitled to something they stopped paying for; the retention entry exists
 * because a learner's recordings must age out even if they never ask.
 */

Schedule::call(fn (SubscriptionService $s) => $s->expireLapsed())
    ->hourly()
    ->name('billing.expire-lapsed')
    ->withoutOverlapping();

Schedule::call(function (SubscriptionService $s) {
    Subscription::whereNotNull('gateway_subscription_id')
        ->whereIn('status', ['active', 'trialing', 'past_due'])
        ->where('current_period_end', '<=', now()->addDay())
        ->each(fn ($subscription) => $s->reconcile($subscription));
})->dailyAt('03:20')->name('billing.reconcile')->withoutOverlapping();

// Speech retention: raw audio past its retention window is deleted while the
// derived scores and anonymised phoneme statistics are kept.
Schedule::call(fn (SpeechRetentionService $r) => $r->purgeExpired())
    ->dailyAt('02:40')
    ->name('speech.purge-expired-audio')
    ->withoutOverlapping();
