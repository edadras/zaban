<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CourseController;
use App\Http\Controllers\Api\V1\ExerciseController;
use App\Http\Controllers\Api\V1\PlacementController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\ProgressController;
use App\Http\Controllers\Api\V1\ReviewController;
use App\Http\Controllers\Api\V1\SessionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1
|--------------------------------------------------------------------------
| Versioned so the Flutter client can be upgraded independently of the server.
| Every response uses the envelope in App\Support\ApiResponse.
|
| Rate limits are tighter on the endpoints that cost money or are attractive to
| brute-force: authentication, AI-backed generation, and speech upload.
*/

Route::prefix('v1')->group(function () {

    // ---------------------------------------------------------------- public
    Route::middleware('throttle:auth')->group(function () {
        Route::post('auth/register', [AuthController::class, 'register']);
        Route::post('auth/login', [AuthController::class, 'login']);
        Route::post('auth/forgot-password', [AuthController::class, 'forgotPassword']);
        Route::post('auth/reset-password', [AuthController::class, 'resetPassword']);
    });

    Route::get('auth/verify/{id}/{hash}', [AuthController::class, 'verifyEmail'])
        ->middleware('signed')->name('verification.verify');

    // -------------------------------------------------------- authenticated
    Route::middleware('auth:sanctum')->group(function () {

        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::post('auth/resend-verification', [AuthController::class, 'resendVerification'])
            ->middleware('throttle:auth');

        // profile
        Route::get('profile', [ProfileController::class, 'show']);
        Route::patch('profile', [ProfileController::class, 'update']);
        Route::patch('profile/settings', [ProfileController::class, 'updateSettings']);
        Route::post('profile/avatar', [ProfileController::class, 'uploadAvatar']);
        Route::post('profile/export', [ProfileController::class, 'requestExport']);
        Route::post('profile/delete', [ProfileController::class, 'requestDeletion']);

        // placement (adaptive test)
        Route::post('placement/start', [PlacementController::class, 'start']);
        Route::get('placement/{session}/next', [PlacementController::class, 'next']);
        Route::post('placement/{session}/submit', [PlacementController::class, 'submit']);
        Route::get('placement/{session}/result', [PlacementController::class, 'result']);

        // curriculum
        Route::get('courses', [CourseController::class, 'index']);
        Route::get('courses/{course}', [CourseController::class, 'show']);
        Route::get('units/{unit}', [CourseController::class, 'unit']);
        Route::get('lessons/{lesson}', [CourseController::class, 'lesson']);

        // the daily adaptive session
        Route::get('session/next', [SessionController::class, 'next']);
        Route::post('session/start', [SessionController::class, 'start']);
        Route::get('session/{session}', [SessionController::class, 'show']);
        Route::post('session/{session}/complete', [SessionController::class, 'complete']);
        Route::post('session/{session}/activities/{activity}/complete',
            [SessionController::class, 'completeActivity']);

        // exercises
        Route::get('exercises/{exercise}', [ExerciseController::class, 'show']);
        Route::get('exercises/{exercise}/hint', [ExerciseController::class, 'hint']);
        Route::post('exercises/{exercise}/submit', [ExerciseController::class, 'submit']);

        // spaced repetition
        Route::get('reviews/due', [ReviewController::class, 'due']);
        Route::get('reviews/counts', [ReviewController::class, 'counts']);

        // progress and analytics
        Route::get('progress/dashboard', [ProgressController::class, 'dashboard']);
        Route::get('progress/skills', [ProgressController::class, 'skills']);
        Route::get('progress/history', [ProgressController::class, 'history']);
        Route::get('progress/trend', [ProgressController::class, 'trend']);

    });
});

/*
 * Module route files are loaded OUTSIDE the authenticated group on purpose:
 * each declares its own middleware, because some of their routes must stay
 * public. Billing webhooks in particular authenticate by gateway signature, not
 * by session, so wrapping them in auth:sanctum would break every delivery.
 *
 *   routes/api/billing.php - plans, subscription, invoices, coupons, webhooks
 *   routes/api/exam.php    - exam types, attempts, AI examiner
 *   routes/api/speech.php  - recording upload, scoring, pronunciation profile
 *   routes/api/admin.php   - ingestion, review queue, AI cost, users
 */
foreach (['billing', 'exam', 'speech', 'admin'] as $module) {
    $path = base_path("routes/api/{$module}.php");
    if (file_exists($path)) {
        require $path;
    }
}
