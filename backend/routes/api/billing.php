<?php

use App\Http\Controllers\Api\V1\Billing\CheckoutController;
use App\Http\Controllers\Api\V1\Billing\CouponController;
use App\Http\Controllers\Api\V1\Billing\InvoiceController;
use App\Http\Controllers\Api\V1\Billing\ManualTransferController;
use App\Http\Controllers\Api\V1\Billing\PlanController;
use App\Http\Controllers\Api\V1\Billing\SubscriptionController;
use App\Http\Controllers\Api\V1\Billing\WebhookController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Public: the price list has to be readable before anyone signs up.
    Route::get('billing/plans', [PlanController::class, 'index']);
    Route::get('billing/plans/{code}', [PlanController::class, 'show']);

    // The gateway signature IS the authentication here, so this must stay
    // outside auth:sanctum. Throttled because gateways retry aggressively.
    Route::post('billing/webhooks/{gateway}', [WebhookController::class, 'handle'])
        ->middleware('throttle:webhooks');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('billing/subscription', [SubscriptionController::class, 'show']);
        Route::post('billing/subscription/cancel', [SubscriptionController::class, 'cancel']);
        Route::post('billing/subscription/resume', [SubscriptionController::class, 'resume']);
        Route::post('billing/subscription/change-plan', [SubscriptionController::class, 'changePlan']);
        Route::post('billing/checkout', [CheckoutController::class, 'store']);
        Route::post('billing/coupons/apply', [CouponController::class, 'store']);
        Route::get('billing/invoices', [InvoiceController::class, 'index']);
        Route::get('billing/invoices/{number}', [InvoiceController::class, 'show']);

        // Card-to-card Rial: instructions → create → upload receipt → admin approves.
        Route::get('billing/manual/instructions', [ManualTransferController::class, 'instructions']);
        Route::get('billing/manual/submissions', [ManualTransferController::class, 'mine']);
        Route::post('billing/manual/submissions', [ManualTransferController::class, 'store']);
        Route::get('billing/manual/submissions/{submission}', [ManualTransferController::class, 'show']);
        Route::post('billing/manual/submissions/{submission}/receipt', [ManualTransferController::class, 'uploadReceipt']);
    });
});
