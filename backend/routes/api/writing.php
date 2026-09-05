<?php

use App\Http\Controllers\Api\V1\Writing\WritingAttemptController;
use Illuminate\Support\Facades\Route;

/*
 * Writing, typed or photographed off paper.
 *
 * Submission is throttled like speech upload: it may carry a photograph and it
 * queues a vision call, so it costs real work per request rather than a lookup.
 */
Route::middleware(['auth:sanctum'])->prefix('v1/writing')->name('writing.')->group(function () {
    Route::get('attempts', [WritingAttemptController::class, 'index'])->name('attempts.index');

    Route::post('attempts', [WritingAttemptController::class, 'store'])
        ->middleware('throttle:speech')->name('attempts.store');

    Route::get('attempts/{attempt}', [WritingAttemptController::class, 'show'])->name('attempts.show');

    /*
     * Accept or correct what was read off a photographed page. Marking does
     * not start until this happens, so the learner is never scored on the
     * recogniser's misreadings.
     */
    Route::post('attempts/{attempt}/confirm', [WritingAttemptController::class, 'confirm'])
        ->name('attempts.confirm');
});
