<?php

use App\Http\Controllers\Api\V1\Classroom\ClassGroupController;
use App\Http\Controllers\Api\V1\Classroom\ClassRoomController;
use App\Http\Controllers\Api\V1\Classroom\ClassSessionController;
use App\Http\Controllers\Api\V1\Classroom\ForumController;
use App\Http\Controllers\Api\V1\Classroom\LiveWebhookController;
use App\Http\Controllers\Api\V1\Classroom\MyClassesController;
use App\Http\Controllers\Api\V1\Classroom\RealtimeController;
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

    // Recording, and watching it back. The playback route is deliberately not
    // under the coach's half: a learner who missed the class is exactly who it
    // is for, and `RecordingService` decides who that includes.
    Route::post('class-sessions/{session}/room/record', [ClassRoomController::class, 'startRecording'])
        ->name('room.record.start');
    Route::post('class-sessions/{session}/room/record/stop', [ClassRoomController::class, 'stopRecording'])
        ->name('room.record.stop');
    Route::get('class-sessions/{session}/recording', [ClassRoomController::class, 'recording'])
        ->name('room.recording');

    // The class reaching into the learner's own day.
    Route::get('class-sessions/{session}/room/lock-preview', [ClassRoomController::class, 'lockPreview'])
        ->name('room.lock.preview');
    Route::post('class-sessions/{session}/room/lock', [ClassRoomController::class, 'lockPractice'])
        ->name('room.lock');
    Route::post('class-sessions/{session}/room/unlock', [ClassRoomController::class, 'releasePractice'])
        ->name('room.unlock');

    // ---------------------------------------------------------- the board
    /*
     * A class's own forum. Every route asks the same first question the rest
     * of the module asks - are you on this roll - so a class's questions
     * cannot leak to a class that is not theirs.
     */
    Route::get('classes/{group}/threads', [ForumController::class, 'index'])->name('forum.index');
    Route::post('classes/{group}/threads', [ForumController::class, 'store'])->name('forum.store');

    Route::get('threads/{thread}', [ForumController::class, 'show'])->name('forum.show');
    Route::patch('threads/{thread}', [ForumController::class, 'update'])->name('forum.update');
    Route::delete('threads/{thread}', [ForumController::class, 'destroy'])->name('forum.destroy');

    Route::post('threads/{thread}/replies', [ForumController::class, 'reply'])->name('forum.reply');
    Route::delete('threads/{thread}/replies/{reply}', [ForumController::class, 'removeReply'])
        ->name('forum.reply.destroy');
    Route::post('threads/{thread}/replies/{reply}/accept', [ForumController::class, 'accept'])
        ->name('forum.accept');
    Route::post('threads/{thread}/replies/{reply}/helpful', [ForumController::class, 'vote'])
        ->name('forum.vote');

    Route::post('threads/{thread}/hide', [ForumController::class, 'hide'])->name('forum.hide');
    Route::post('threads/{thread}/restore', [ForumController::class, 'restore'])->name('forum.restore');
    Route::post('threads/{thread}/replies/{reply}/hide', [ForumController::class, 'hideReply'])
        ->name('forum.reply.hide');
    Route::post('threads/{thread}/replies/{reply}/endorse', [ForumController::class, 'endorse'])
        ->name('forum.endorse');
    Route::post('threads/{thread}/pin', [ForumController::class, 'pin'])->name('forum.pin');
    Route::post('threads/{thread}/lock', [ForumController::class, 'lock'])->name('forum.lock');

    // ---------------------------------------------------------- the learner
    Route::get('my/classes', [MyClassesController::class, 'index'])->name('my.classes');
    Route::get('my/classes/history', [MyClassesController::class, 'history'])->name('my.history');

    /*
     * Live updates.
     *
     * Laravel's own /broadcasting/auth is behind the session guard, which is
     * right for the panel and useless to a bearer-token client. This is the
     * same authorisation - the callbacks in routes/channels.php - reached the
     * way the app authenticates everything else.
     */
    Route::get('realtime', [RealtimeController::class, 'show'])->name('realtime.show');
    Route::post('realtime/auth', [RealtimeController::class, 'authorise'])->name('realtime.auth');

    // ------------------------------------------------------------- the bell
    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
    Route::post('notifications/{id}/read', [NotificationController::class, 'read'])->name('notifications.read');
});

/*
 * The media server reporting a finished recording.
 *
 * Outside the authenticated group on purpose: it is a server calling a server.
 * It authenticates by the signature LiveKit puts on the body, which the
 * controller checks before reading a single field.
 */
Route::post('v1/webhooks/live', LiveWebhookController::class)
    ->middleware('throttle:60,1')
    ->name('classroom.webhooks.live');
