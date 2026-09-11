<?php

use App\Http\Controllers\Panel\ClassGroupController;
use App\Http\Controllers\Panel\ClassSessionController;
use App\Http\Controllers\Panel\DashboardController;
use App\Http\Controllers\Panel\ForumController;
use App\Http\Controllers\Panel\HomeworkController;
use App\Http\Controllers\Panel\LoginController;
use App\Http\Controllers\Panel\NotificationController;
use App\Http\Controllers\Panel\PlatformController;
use App\Http\Controllers\Panel\RoomController;
use App\Http\Controllers\Panel\SchoolController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('panel.login'));

/*
|--------------------------------------------------------------------------
| The web panel
|--------------------------------------------------------------------------
| A website for the two people who do their work at a desk: the school's
| administrator and the coach. The learner's half of the product stays in the
| app, and this is not a second copy of it.
|
| Session authentication rather than bearer tokens, and the classroom services
| called directly rather than the panel talking to its own API over HTTP - one
| set of rules, enforced once, whichever door a school comes in through.
*/
Route::prefix('panel')->name('panel.')->group(function () {

    Route::middleware('guest')->group(function () {
        Route::get('login', [LoginController::class, 'show'])->name('login');
        Route::post('login', [LoginController::class, 'store'])->name('login.attempt');
    });
    Route::post('logout', [LoginController::class, 'destroy'])->name('logout');

    Route::middleware(['auth', 'panel'])->group(function () {

        Route::get('/', [DashboardController::class, 'index'])->name('home');

        // ----------------------------------------------------------- schools
        // Creating a school is a platform-admin action (see platform.schools).
        Route::get('schools', [SchoolController::class, 'index'])->name('schools.index');
        Route::get('schools/{school}', [SchoolController::class, 'show'])->name('schools.show');
        Route::patch('schools/{school}', [SchoolController::class, 'update'])->name('schools.update');

        Route::get('schools/{school}/people', [SchoolController::class, 'people'])->name('schools.people');
        Route::post('schools/{school}/people', [SchoolController::class, 'addMember'])->name('schools.people.store');
        Route::delete('schools/{school}/people/{member}', [SchoolController::class, 'removeMember'])
            ->name('schools.people.destroy');

        Route::get('schools/{school}/coaches/{coach}', [SchoolController::class, 'coach'])
            ->name('schools.coach');
        Route::post('schools/{school}/coaches/{coach}/students', [SchoolController::class, 'assignStudent'])
            ->name('schools.coach.assign');
        Route::delete('schools/{school}/coaches/{coach}/students/{student}',
            [SchoolController::class, 'unassignStudent'])->name('schools.coach.unassign');

        // ----------------------------------------------------------- classes
        Route::get('classes', [ClassGroupController::class, 'index'])->name('classes.index');
        Route::post('classes', [ClassGroupController::class, 'store'])->name('classes.store');
        Route::get('classes/{group}', [ClassGroupController::class, 'show'])->name('classes.show');
        Route::patch('classes/{group}', [ClassGroupController::class, 'update'])->name('classes.update');
        Route::delete('classes/{group}', [ClassGroupController::class, 'destroy'])->name('classes.destroy');

        Route::post('classes/{group}/students', [ClassGroupController::class, 'enrol'])->name('classes.enrol');
        Route::delete('classes/{group}/students/{student}', [ClassGroupController::class, 'withdraw'])
            ->name('classes.withdraw');

        Route::post('classes/{group}/rules', [ClassGroupController::class, 'addRule'])->name('classes.rules.store');
        Route::delete('classes/{group}/rules/{rule}', [ClassGroupController::class, 'removeRule'])
            ->name('classes.rules.destroy');
        Route::post('classes/{group}/generate', [ClassGroupController::class, 'generate'])
            ->name('classes.generate');

        // ------------------------------------------------------------- board
        Route::get('classes/{group}/board', [ForumController::class, 'index'])->name('forum.index');
        Route::post('classes/{group}/board', [ForumController::class, 'store'])->name('forum.store');
        Route::get('board/{thread}', [ForumController::class, 'show'])->name('forum.show');
        Route::post('board/{thread}/replies', [ForumController::class, 'reply'])->name('forum.reply');
        Route::post('board/{thread}/replies/{reply}/accept', [ForumController::class, 'accept'])
            ->name('forum.accept');
        Route::post('board/{thread}/replies/{reply}/endorse', [ForumController::class, 'endorse'])
            ->name('forum.endorse');
        Route::post('board/{thread}/replies/{reply}/hide', [ForumController::class, 'hideReply'])
            ->name('forum.reply.hide');
        Route::post('board/{thread}/hide', [ForumController::class, 'hide'])->name('forum.hide');
        Route::post('board/{thread}/restore', [ForumController::class, 'restore'])->name('forum.restore');
        Route::post('board/{thread}/pin', [ForumController::class, 'pin'])->name('forum.pin');
        Route::post('board/{thread}/lock', [ForumController::class, 'lock'])->name('forum.lock');

        // ---------------------------------------------------------- homework
        Route::get('classes/{group}/homework', [HomeworkController::class, 'index'])->name('homework.index');
        Route::post('classes/{group}/homework', [HomeworkController::class, 'store'])->name('homework.store');
        Route::get('homework/{assignment}', [HomeworkController::class, 'show'])->name('homework.show');
        Route::post('homework/{assignment}/items', [HomeworkController::class, 'addItem'])
            ->name('homework.items.store');
        Route::delete('homework/{assignment}/items/{item}', [HomeworkController::class, 'removeItem'])
            ->name('homework.items.destroy');
        Route::post('homework/{assignment}/publish', [HomeworkController::class, 'publish'])
            ->name('homework.publish');
        Route::get('homework/{assignment}/submissions/{submission}', [HomeworkController::class, 'submission'])
            ->name('homework.submission');
        Route::post('homework/{assignment}/submissions/{submission}/mark', [HomeworkController::class, 'mark'])
            ->name('homework.mark');
        Route::post('homework/{assignment}/return-all', [HomeworkController::class, 'releaseAll'])
            ->name('homework.return-all');

        // ---------------------------------------------------------- sessions
        Route::get('sessions', [ClassSessionController::class, 'index'])->name('sessions.index');
        Route::post('sessions', [ClassSessionController::class, 'store'])->name('sessions.store');
        Route::get('sessions/{session}', [ClassSessionController::class, 'show'])->name('sessions.show');
        Route::patch('sessions/{session}', [ClassSessionController::class, 'update'])->name('sessions.update');
        Route::post('sessions/{session}/cancel', [ClassSessionController::class, 'cancel'])->name('sessions.cancel');

        Route::post('sessions/{session}/materials', [ClassSessionController::class, 'addMaterial'])
            ->name('sessions.materials.store');
        Route::delete('sessions/{session}/materials/{material}', [ClassSessionController::class, 'removeMaterial'])
            ->name('sessions.materials.destroy');
        Route::post('sessions/{session}/materials/reorder', [ClassSessionController::class, 'reorderMaterials'])
            ->name('sessions.materials.reorder');

        Route::post('sessions/{session}/start', [ClassSessionController::class, 'start'])->name('sessions.start');
        Route::post('sessions/{session}/summon', [ClassSessionController::class, 'summon'])->name('sessions.summon');
        Route::post('sessions/{session}/end', [ClassSessionController::class, 'end'])->name('sessions.end');
        Route::get('sessions/{session}/attendance', [ClassSessionController::class, 'attendance'])
            ->name('sessions.attendance');
        Route::get('sessions/{session}/recording', [ClassSessionController::class, 'recording'])
            ->name('sessions.recording');

        // -------------------------------------------------------- the studio
        Route::get('sessions/{session}/room', [RoomController::class, 'show'])->name('sessions.room');

        // ----------------------------------------------------------- the bell
        Route::get('notifications', [NotificationController::class, 'index'])->name('notifications');
        Route::post('notifications/read-all', [NotificationController::class, 'readAll'])
            ->name('notifications.read-all');

        // --------------------------------------------------------- platform
        Route::prefix('platform')->name('platform.')->group(function () {
            Route::get('/', [PlatformController::class, 'overview'])->name('overview');
            Route::get('schools', [PlatformController::class, 'schools'])->name('schools');
            Route::post('schools', [PlatformController::class, 'storeSchool'])->name('schools.store');
            Route::get('users', [PlatformController::class, 'users'])->name('users');
            Route::patch('users/{user}', [PlatformController::class, 'updateUser'])->name('users.update');
            Route::get('audit', [PlatformController::class, 'audit'])->name('audit');
        });
    });
});
