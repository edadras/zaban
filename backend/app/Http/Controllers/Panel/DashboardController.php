<?php

namespace App\Http\Controllers\Panel;

use App\Models\ClassGroup;
use App\Models\ClassSession;
use App\Models\SchoolMember;
use Illuminate\Support\Carbon;

/**
 * The first page after signing in.
 *
 * Written around the question a coach actually opens the panel to ask - what
 * am I teaching next, and is it ready - rather than around a grid of totals.
 */
class DashboardController extends PanelController
{
    public function index()
    {
        $user = $this->me();

        $groupIds = ClassGroup::query()
            ->where(function ($q) use ($user) {
                $q->where('coach_id', $user->id)
                    ->orWhereIn('school_id', SchoolMember::where('user_id', $user->id)
                        ->whereIn('role', SchoolMember::MANAGERS)
                        ->where('status', 'active')
                        ->pluck('school_id'));
            })
            ->pluck('id');

        $sessions = ClassSession::with(['group.school', 'coach'])
            ->withCount(['materials', 'participants'])
            ->whereIn('class_group_id', $groupIds)
            ->whereIn('status', [ClassSession::SCHEDULED, ClassSession::LIVE])
            ->where('starts_at', '>=', now()->subHours(2))
            ->orderBy('starts_at')
            ->limit(25)
            ->get();

        $managed = $this->access->managedSchools($user);

        return view('panel.dashboard', [
            'live' => $sessions->where('status', ClassSession::LIVE)->values(),
            'today' => $sessions->filter(
                fn (ClassSession $s) => $s->status === ClassSession::SCHEDULED
                    && $s->starts_at?->isSameDay(Carbon::now())
            )->values(),
            'upcoming' => $sessions->filter(
                fn (ClassSession $s) => $s->status === ClassSession::SCHEDULED
                    && ! $s->starts_at?->isSameDay(Carbon::now())
            )->values(),
            'managedSchools' => $managed,
            'coachingSchools' => $this->access->coachingSchools($user),
            'groupCount' => $groupIds->count(),
            'studentCount' => SchoolMember::whereIn('school_id', $managed->pluck('id'))
                ->where('role', SchoolMember::STUDENT)
                ->where('status', 'active')
                ->distinct('user_id')
                ->count('user_id'),
            'coachCount' => SchoolMember::whereIn('school_id', $managed->pluck('id'))
                ->where('role', SchoolMember::COACH)
                ->where('status', 'active')
                ->distinct('user_id')
                ->count('user_id'),
        ]);
    }
}
