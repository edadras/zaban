<?php

use App\Http\Controllers\Api\V1\Speech\PronunciationProfileController;
use App\Http\Controllers\Api\V1\Speech\SpeechAttemptController;
use App\Http\Controllers\Api\V1\Speech\SpeechRecordingController;
use Illuminate\Support\Facades\Route;

/*
 * Speech. Upload is throttled harder than the rest of the group: it writes a
 * file and queues a transcription, so it costs real work per call.
 */
Route::middleware(['auth:sanctum'])->prefix('v1/speech')->name('speech.')->group(function () {
    Route::get('attempts', [SpeechAttemptController::class, 'index'])->name('attempts.index');
    Route::post('attempts', [SpeechAttemptController::class, 'store'])
        ->middleware('throttle:speech')->name('attempts.store');
    Route::get('attempts/{attempt}', [SpeechAttemptController::class, 'show'])->name('attempts.show');

    // Privacy (spec 45): deletes the audio only - scores and the anonymised
    // phoneme statistics survive.
    Route::delete('attempts/{attempt}/recording', [SpeechRecordingController::class, 'destroy'])
        ->name('recordings.destroy');
    Route::delete('recordings', [SpeechRecordingController::class, 'destroyAll'])
        ->name('recordings.destroy-all');

    Route::get('profile', [PronunciationProfileController::class, 'show'])->name('profile.show');
    Route::get('profile/drills', [PronunciationProfileController::class, 'drills'])->name('profile.drills');
});
