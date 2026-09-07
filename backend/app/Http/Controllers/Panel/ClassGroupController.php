<?php

namespace App\Http\Controllers\Panel;

use App\Models\CefrLevel;
use App\Models\ClassGroup;
use App\Models\ClassScheduleRule;
use App\Models\ClassSession;
use App\Models\School;
use App\Models\SchoolMember;
use App\Models\User;
use App\Services\Classroom\ClassGroupService;
use App\Services\Classroom\ClassScheduleService;
use App\Services\Classroom\SchoolService;
use App\Support\PanelAccess;
use Illuminate\Http\Request;

/**
 * A coach's classes: the roll, the weekly times, and the diary they produce.
 */
class ClassGroupController extends PanelController
{
    public function __construct(
        PanelAccess $access,
        private readonly ClassGroupService $groups,
        private readonly ClassScheduleService $schedule,
        private readonly SchoolService $schools,
    ) {
        parent::__construct($access);
    }

    public function index()
    {
        $user = $this->me();

        $groups = ClassGroup::with(['school', 'coach', 'level'])
            ->withCount(['students', 'sessions'])
            ->where(function ($q) use ($user) {
                $q->where('coach_id', $user->id)
                    ->orWhereIn('school_id', SchoolMember::where('user_id', $user->id)
                        ->whereIn('role', SchoolMember::MANAGERS)
                        ->where('status', 'active')
                        ->pluck('school_id'));
            })
            ->orderByDesc('is_active')->orderBy('title')
            ->get();

        return view('panel.classes.index', [
            'groups' => $groups,
            'schools' => $this->access->managedSchools($user),
            'levels' => CefrLevel::orderBy('id')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'school_id' => ['required', 'integer', 'exists:schools,id'],
            'coach_id' => ['nullable', 'integer', 'exists:users,id'],
            'title' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
            'cefr_level_id' => ['nullable', 'integer', 'exists:cefr_levels,id'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $school = School::findOrFail($data['school_id']);
        $this->allow($this->access->canManageSchool($this->me(), $school));

        $coachId = $data['coach_id'] ?? $this->me()->id;

        // A class is taught by somebody this school teaches with. Without the
        // check an administrator could point a class at any account at all.
        $this->allow(
            $coachId === $this->me()->id
                || SchoolMember::where('school_id', $school->id)->where('user_id', $coachId)
                    ->where('role', SchoolMember::COACH)->where('status', 'active')->exists(),
            'آن شخص مربی این آموزشگاه نیست.',
        );

        $group = ClassGroup::create([
            'school_id' => $school->id,
            'coach_id' => $coachId,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'cefr_level_id' => $data['cefr_level_id'] ?? null,
            'capacity' => $data['capacity'] ?? 20,
            'timezone' => $school->timezone,
            'is_active' => true,
        ]);

        return redirect()->route('panel.classes.show', $group)->with('status', 'کلاس ساخته شد.');
    }

    public function show(ClassGroup $group)
    {
        $this->allow($this->access->canSeeGroup($this->me(), $group));

        $group->load(['school', 'coach', 'level', 'rules', 'students']);

        $enrolled = $group->students->pluck('id');

        return view('panel.classes.show', [
            'group' => $group,
            'levels' => CefrLevel::orderBy('id')->get(),
            'isManager' => $this->access->canManageSchool($this->me(), $group->school),
            'coaches' => SchoolMember::with('user')
                ->where('school_id', $group->school_id)
                ->where('role', SchoolMember::COACH)
                ->where('status', 'active')
                ->get()
                ->sortBy(fn (SchoolMember $m) => $m->user?->name ?? '')
                ->values(),
            'rules' => $group->rules->where('is_active', true)->values(),
            'sessions' => ClassSession::withCount(['materials', 'participants'])
                ->where('class_group_id', $group->id)
                ->where('starts_at', '>=', now()->subWeek())
                ->orderBy('starts_at')->limit(60)->get(),
            'candidates' => SchoolMember::with('user')
                ->where('school_id', $group->school_id)
                ->where('role', SchoolMember::STUDENT)
                ->where('status', 'active')
                ->get()
                ->reject(fn (SchoolMember $m) => $enrolled->contains($m->user_id))
                ->sortBy(fn (SchoolMember $m) => $m->user?->name ?? '')
                ->values(),
        ]);
    }

    public function update(Request $request, ClassGroup $group)
    {
        $this->allow($this->access->canManageGroup($this->me(), $group));

        $data = $request->validate([
            'coach_id' => ['nullable', 'integer', 'exists:users,id'],
            'title' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
            'cefr_level_id' => ['nullable', 'integer', 'exists:cefr_levels,id'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:200'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $newCoach = $data['coach_id'] ?? null;
        unset($data['coach_id']);

        // Handing a class over is a staffing decision, so it belongs to the
        // school rather than to the coach currently holding it.
        if ($newCoach !== null && $newCoach !== $group->coach_id) {
            $this->allow(
                $this->access->canManageSchool($this->me(), $group->school),
                'تغییر مربی کلاس تنها از عهدهٔ مدیر آموزشگاه برمی‌آید.',
            );

            return $this->attempt(
                function () use ($group, $newCoach, $data, $request) {
                    $this->schools->reassignClass($group, User::findOrFail($newCoach));
                    $group->update($data + ['is_active' => $request->boolean('is_active')]);
                },
                route('panel.classes.show', $group),
                'کلاس به مربی تازه سپرده شد.',
            );
        }

        $group->update($data + ['is_active' => $request->boolean('is_active')]);

        return back()->with('status', 'ذخیره شد.');
    }

    public function destroy(ClassGroup $group)
    {
        $this->allow($this->access->canManageGroup($this->me(), $group));

        $group->delete();

        return redirect()->route('panel.classes.index')->with('status', 'کلاس بایگانی شد.');
    }

    // ------------------------------------------------------------------ roll

    public function enrol(Request $request, ClassGroup $group)
    {
        $this->allow($this->access->canManageGroup($this->me(), $group));

        $data = $request->validate([
            'user_ids' => ['required', 'array', 'min:1'],
            'user_ids.*' => ['integer', 'exists:users,id'],
        ]);

        return $this->attempt(
            function () use ($group, $data) {
                $result = $this->groups->enrol($group, $data['user_ids']);

                session()->flash('rejected', $result['rejected']);
            },
            route('panel.classes.show', $group),
            'زبان‌آموزان ثبت‌نام شدند.',
        );
    }

    public function withdraw(ClassGroup $group, User $student)
    {
        $this->allow($this->access->canManageGroup($this->me(), $group));

        $this->groups->withdraw($group, $student);

        return back()->with('status', 'از کلاس خارج شد.');
    }

    // -------------------------------------------------------------- timetable

    public function addRule(Request $request, ClassGroup $group)
    {
        $this->allow($this->access->canManageGroup($this->me(), $group));

        $data = $request->validate([
            'weekday' => ['required', 'integer', 'min:0', 'max:6'],
            'start_time' => ['required', 'date_format:H:i'],
            'duration_minutes' => ['required', 'integer', 'min:10', 'max:480'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['nullable', 'date', 'after:starts_on'],
            'generate_weeks' => ['nullable', 'integer', 'min:1', 'max:52'],
        ]);

        return $this->attempt(
            fn () => $this->groups->addRule($group, $data),
            route('panel.classes.show', $group),
            'زمان هفتگی افزوده شد و تقویم پر شد.',
        );
    }

    public function removeRule(ClassGroup $group, ClassScheduleRule $rule)
    {
        $this->allow($this->access->canManageGroup($this->me(), $group));

        return $this->attempt(
            fn () => $this->groups->removeRule($group, $rule),
            route('panel.classes.show', $group),
            'زمان هفتگی برداشته شد و جلسه‌های آینده‌اش لغو شدند.',
        );
    }

    public function generate(Request $request, ClassGroup $group)
    {
        $this->allow($this->access->canManageGroup($this->me(), $group));

        $created = $this->schedule->generate($group, (int) $request->integer('weeks', 8));

        return back()->with('status', "{$created} جلسه به تقویم افزوده شد.");
    }
}
