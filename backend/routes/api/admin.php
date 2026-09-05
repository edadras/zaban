<?php

use App\Http\Controllers\Api\V1\Admin\AiUsageController;
use App\Http\Controllers\Api\V1\Admin\ContentReviewController;
use App\Http\Controllers\Api\V1\Admin\IngestionController;
use App\Http\Controllers\Api\V1\Admin\UserAdminController;
use Illuminate\Support\Facades\Route;

/*
 * Administration. Loaded from routes/api.php inside the authenticated group,
 * then further gated on an admin/editor/reviewer role.
 */
Route::prefix('admin')->middleware('admin')->group(function () {

    // content ingestion dashboard
    Route::get('ingestion/summary', [IngestionController::class, 'summary']);
    Route::get('ingestion/documents', [IngestionController::class, 'documents']);
    Route::get('ingestion/jobs', [IngestionController::class, 'jobs']);
    Route::get('ingestion/jobs/{job}', [IngestionController::class, 'job']);
    Route::get('ingestion/issues', [IngestionController::class, 'issues']);
    Route::get('ingestion/audio/unmapped', [IngestionController::class, 'unmappedAudio']);
    Route::post('ingestion/audio/{mapping}/review', [IngestionController::class, 'reviewAudioMapping']);

    // generated content review
    Route::get('content/queue', [ContentReviewController::class, 'queue']);
    Route::get('content/reviews/{review}', [ContentReviewController::class, 'show']);
    Route::post('content/reviews/{review}/revalidate', [ContentReviewController::class, 'revalidate']);
    Route::post('content/reviews/{review}/decide', [ContentReviewController::class, 'decide']);
    Route::post('content/validate-batch', [ContentReviewController::class, 'validateBatch']);
    Route::post('content/auto-publish', [ContentReviewController::class, 'autoPublish']);

    // AI cost and reliability
    Route::get('ai/overview', [AiUsageController::class, 'overview']);
    Route::get('ai/daily', [AiUsageController::class, 'daily']);
    Route::get('ai/failures', [AiUsageController::class, 'failures']);
    Route::get('ai/providers', [AiUsageController::class, 'providers']);
    Route::get('ai/limits', [AiUsageController::class, 'limits']);
    Route::post('ai/limits', [AiUsageController::class, 'setLimit']);

    // users
    Route::get('users', [UserAdminController::class, 'index']);
    Route::get('users/{user}', [UserAdminController::class, 'show']);
    Route::patch('users/{user}', [UserAdminController::class, 'update']);
    Route::get('audit-log', [UserAdminController::class, 'auditLog']);
});
