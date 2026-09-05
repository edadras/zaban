<?php

use App\Http\Controllers\Api\V1\Exam\ExamAttemptController;
use App\Http\Controllers\Api\V1\Exam\ExamProgressController;
use App\Http\Controllers\Api\V1\Exam\ExamSpeakingController;
use App\Http\Controllers\Api\V1\Exam\ExamTypeController;
use Illuminate\Support\Facades\Route;

/*
 * Exam preparation. Finishing an attempt and the speaking examiner each cost
 * model calls, so those three sit behind the tighter 'ai' limiter.
 */
Route::middleware(['auth:sanctum'])->prefix('v1/exams')->name('exams.')->group(function () {
    Route::get('types', [ExamTypeController::class, 'index'])->name('types.index');
    Route::get('types/{examType}', [ExamTypeController::class, 'show'])->name('types.show');

    Route::post('attempts', [ExamAttemptController::class, 'store'])->name('attempts.store');
    Route::get('attempts/{attempt}', [ExamAttemptController::class, 'show'])->name('attempts.show');
    Route::get('attempts/{attempt}/next-task', [ExamAttemptController::class, 'nextTask'])->name('attempts.next-task');
    Route::post('attempts/{attempt}/tasks/{task}/response', [ExamAttemptController::class, 'submit'])->name('attempts.submit');
    Route::post('attempts/{attempt}/finish', [ExamAttemptController::class, 'finish'])
        ->middleware('throttle:ai')->name('attempts.finish');
    Route::get('attempts/{attempt}/results', [ExamAttemptController::class, 'results'])->name('attempts.results');

    Route::get('attempts/{attempt}/speaking', [ExamSpeakingController::class, 'next'])->name('speaking.next');
    Route::post('attempts/{attempt}/speaking/response', [ExamSpeakingController::class, 'respond'])
        ->middleware('throttle:ai')->name('speaking.respond');
    Route::get('attempts/{attempt}/speaking/score', [ExamSpeakingController::class, 'score'])
        ->middleware('throttle:ai')->name('speaking.score');

    Route::get('progress', [ExamProgressController::class, 'index'])->name('progress.index');
});
