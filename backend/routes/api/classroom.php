<?php

use App\Http\Controllers\Api\V1\Classroom\ClassGroupController;
use App\Http\Controllers\Api\V1\Classroom\ClassRoomController;
use App\Http\Controllers\Api\V1\Classroom\ClassSessionController;
use App\Http\Controllers\Api\V1\Classroom\MyClassesController;
use App\Http\Controllers\Api\V1\Classroom\SchoolController;
use App\Http\Controllers\Api\V1\NotificationController;
use Illuminate\Support\Facades\Route;

/*
 * Schools, coaches and live classes.
 *
 * There is no `admin` middleware on any of this. A school's owner is not a
 * platform administrator, and a coach is not one either - authorisation here is
 * "do you manage this school" and "is this your class", asked per row inside
 * the controllers. Putting the platform's admin gate in front would let any
 * editor into every school and keep every school's own owner out.
 */
Route::middleware(['auth:sanctum'])->prefix('v1')->name('classroom.')->group(function () {

    // -------------------------------------------------------------- schools
    Route::get('schools', [SchoolController::class, 'index'])->name('schools.index');
    Route::post('schools', [SchoolController::class, 'store'])->name('schools.store');
    Route::get('schools/{school}', [SchoolController::class, 'show'])->name('schools.show');
    Route::patch('schools/{school}', [SchoolController::class, 'update'])->name('schools.update');

    Route::get('schools/{school}/members', [SchoolController::class, 'members'])->name('members.index');
    Route::post('schools/{school}/members', [SchoolController::class, 'addMember'])->name('members.store');
    Route::delete('schools/{school}/members/{member}', [SchoolController::class, 'removeMember'])
        ->name('members.destroy');

    // The admin drawing a line between a coach and a learner.
    Route::get('schools/{school}/coaches/{coach}/students', [SchoolController::class, 'coachStudents'])
        ->name('coach.students');
    Route::post('schools/{school}/coaches/{coach}/students', [SchoolController::class, 'assignStudent'])
        ->name('coach.students.store');
    Route::delete('schools/{school}/coaches/{coach}/students/{student}', [SchoolController::class, 'unassignStudent'])
        ->name('coach.students.destroy');

    // -------------------------------------------------------------- classes
    Route::get('classes', [ClassGroupController::class, 'index'])->name('classes.index');
    Route::post('classes', [ClassGroupController::class, 'store'])->name('classes.store');
    Route::get('classes/{group}', [ClassGroupController::class, 'show'])->name('classes.show');
    Route::patch('classes/{group}', [ClassGroupController::class, 'update'])->name('classes.update');
    Route::delete('classes/{group}', [ClassGroupController::class, 'destroy'])->name('classes.destroy');

    Route::post('classes/{group}/students', [ClassGroupController::class, 'enrol'])->name('classes.enrol');
    Route::delete('classes/{group}/students/{student}', [ClassGroupController::class, 'withdraw'])
        ->name('classes.withdraw');

    Route::post('classes/{group}/rules', [ClassGroupController::class, 'addRule'])->name('rules.store');
    Route::delete('classes/{group}/rules/{rule}', [ClassGroupController::class, 'removeRule'])->name('rules.destroy');
    Route::post('classes/{group}/generate', [ClassGroupController::class, 'generate'])->name('classes.generate');

    // ------------------------------------------------------------- sessions
    Route::get('class-sessions', [ClassSessionController::class, 'index'])->name('sessions.index');
    Route::post('class-sessions', [ClassSessionController::class, 'store'])->name('sessions.store');
    Route::get('class-sessions/{session}', [ClassSessionController::class, 'show'])->name('sessions.show');
    Route::patch('class-sessions/{session}', [ClassSessionController::class, 'update'])->name('sessions.update');
    Route::post('class-sessions/{session}/cancel', [ClassSessionController::class, 'cancel'])->name('sessions.cancel');
    Route::get('class-sessions/{session}/attendance', [ClassSessionController::class, 'attendance'])
        ->name('sessions.attendance');

    // Preparing the lesson. The upload limit is generous because a coach's
    // video is the point, so it carries the wider 'media' throttle.
    Route::post('class-sessions/{session}/materials', [ClassSessionController::class, 'addMaterial'])
        ->name('materials.store');
    Route::delete('class-sessions/{session}/materials/{material}', [ClassSessionController::class, 'removeMaterial'])
        ->name('materials.destroy');
    Route::post('class-sessions/{session}/materials/reorder', [ClassSessionController::class, 'reorderMaterials'])
        ->name('materials.reorder');

    // ----------------------------------------------------------- the room
    Route::post('class-sessions/{session}/start', [ClassSessionController::class, 'start'])->name('room.start');
    Route::post('class-sessions/{session}/end', [ClassSessionController::class, 'end'])->name('room.end');
    Route::post('class-sessions/{session}/summon', [ClassSessionController::class, 'summon'])->name('room.summon');

    Route::get('class-sessions/{session}/room', [ClassRoomController::class, 'show'])->name('room.show');
    Route::post('class-sessions/{session}/room/join', [ClassRoomController::class, 'join'])->name('room.join');
    Route::post('class-sessions/{session}/room/leave', [ClassRoomController::class, 'leave'])->name('room.leave');
    Route::post('class-sessions/{session}/room/token', [ClassRoomController::class, 'token'])->name('room.token');
    Route::post('class-sessions/{session}/room/hand', [ClassRoomController::class, 'raiseHand'])->name('room.hand');

    Route::post('class-sessions/{session}/room/participants/{participant}/media',
        [ClassRoomController::class, 'setMedia'])->name('room.media');
    Route::post('class-sessions/{session}/room/mute-all', [ClassRoomController::class, 'muteAll'])
        ->name('room.mute-all');
    Route::delete('class-sessions/{session}/room/participants/{participant}',
        [ClassRoomController::class, 'removeParticipant'])->name('room.remove');

    Route::post('class-sessions/{session}/room/materials/{material}/share',
        [ClassRoomController::class, 'shareMaterial'])->name('room.share');
    Route::post('class-sessions/{session}/room/materials/{material}/close',
        [ClassRoomController::class, 'closeMaterial'])->name('room.close');

    // What today's shelf can be asked, so a coach picks a corpus question
    // rather than retyping one the system already holds.
    Route::get('class-sessions/{session}/room/askable', [ClassRoomController::class, 'askable'])
        ->name('room.askable');
    Route::post('class-sessions/{session}/room/questions', [ClassRoomController::class, 'askQuestion'])
        ->name('room.ask');
    Route::post('class-sessions/{session}/room/questions/{question}/answer',
        [ClassRoomController::class, 'answerQuestion'])->name('room.answer');
    Route::post('class-sessions/{session}/room/questions/{question}/close',
        [ClassRoomController::class, 'closeQuestion'])->name('room.question.close');
    Route::get('class-sessions/{session}/room/questions/{question}',
        [ClassRoomController::class, 'questionResults'])->name('room.question.show');

    // The class reaching into the learner's own day.
    Route::get('class-sessions/{session}/room/lock-preview', [ClassRoomController::class, 'lockPreview'])
        ->name('room.lock.preview');
    Route::post('class-sessions/{session}/room/lock', [ClassRoomController::class, 'lockPractice'])
        ->name('room.lock');
    Route::post('class-sessions/{session}/room/unlock', [ClassRoomController::class, 'releasePractice'])
        ->name('room.unlock');

    // ---------------------------------------------------------- the learner
    Route::get('my/classes', [MyClassesController::class, 'index'])->name('my.classes');
    Route::get('my/classes/history', [MyClassesController::class, 'history'])->name('my.history');

    // ------------------------------------------------------------- the bell
    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
    Route::post('notifications/{id}/read', [NotificationController::class, 'read'])->name('notifications.read');
});
