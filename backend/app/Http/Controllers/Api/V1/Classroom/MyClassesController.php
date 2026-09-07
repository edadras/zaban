<?php

namespace App\Http\Controllers\Api\V1\Classroom;

use App\Http\Controllers\Api\V1\ApiController;
use App\Models\ClassGroup;
use App\Models\ClassSession;
use App\Models\CoachStudent;
use App\Models\PracticeLock;
use Illuminate\Http\Request;

/**
 * The learner's side of the school: my classes, my next lesson, my coach.
 *
 * Deliberately read-only. A learner joins a class through
 * {@see ClassRoomController} and does nothing else here, so there is no route
 * on which enrolment could be self-served.
 */
class MyClassesController extends ApiController
{
    /** Everything the home screen needs to say "you have a class at six". */
    public function index(Request $request)
    {
        $user = $request->user();

        $groupIds = ClassGroup::whereHas('students', fn ($q) => $q->whereKey($user->id))
            ->pluck('id');

        $upcoming = ClassSession::with(['group.school', 'coach'])
            ->whereIn('class_group_id', $groupIds)
            ->whereIn('status', [ClassSession::SCHEDULED, ClassSession::LIVE])
            ->where('ends_at', '>=', now()->subHour())
            ->orderBy('starts_at')
            ->limit(20)
            ->get();

        $coaches = CoachStudent::with('coach', 'school')
            ->where('student_id', $user->id)
            ->whereNull('ended_at')
            ->get();

        $lock = PracticeLock::forUser($user->id);

        return $this->ok([
            'classes' => ClassGroup::with(['school', 'coach', 'level'])
                ->whereIn('id', $groupIds)
                ->get()
                ->map(fn (ClassGroup $g) => [
                    'id' => $g->id,
                    'title' => $g->title,
                    'school' => $g->school?->name,
                    'coach' => $g->coach?->name,
                    'cefr' => $g->level?->code,
                ]),
            'upcoming' => $upcoming->map(fn (ClassSession $s) => [
                'id' => $s->id,
                'title' => $s->title ?: $s->group?->title,
                'coach' => $s->coach?->name,
                'starts_at' => $s->starts_at?->toIso8601String(),
                'ends_at' => $s->ends_at?->toIso8601String(),
                'status' => $s->status,
                'is_joinable' => $s->isJoinable(),
                'minutes_until' => (int) round(now()->diffInMinutes($s->starts_at, absolute: false)),
            ]),
            'coaches' => $coaches->map(fn (CoachStudent $c) => [
                'id' => $c->coach_id,
                'name' => $c->coach?->name,
                'school' => $c->school?->name,
            ]),
            // Shown on the home screen so a learner understands why today's
            // practice is what it is, rather than thinking the app broke.
            'practice_lock' => $lock === null ? null : [
                'id' => $lock->id,
                'note' => $lock->note,
                'class_session_id' => $lock->class_session_id,
                'expires_at' => $lock->expires_at?->toIso8601String(),
                'concept_count' => count($lock->concept_ids ?? []),
            ],
        ]);
    }

    /** What happened in a class I attended. */
    public function history(Request $request)
    {
        $user = $request->user();

        $sessions = ClassSession::with(['group', 'coach'])
            ->whereHas('participants', fn ($q) => $q->where('user_id', $user->id))
            ->where('status', ClassSession::ENDED)
            ->orderByDesc('starts_at')
            ->limit(50)
            ->get();

        return $this->ok($sessions->map(fn (ClassSession $s) => [
            'id' => $s->id,
            'title' => $s->title ?: $s->group?->title,
            'coach' => $s->coach?->name,
            'starts_at' => $s->starts_at?->toIso8601String(),
            'seconds_present' => $s->participants->firstWhere('user_id', $user->id)?->seconds_present ?? 0,
        ]));
    }
}
