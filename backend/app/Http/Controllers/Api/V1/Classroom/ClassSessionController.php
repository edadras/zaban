<?php

namespace App\Http\Controllers\Api\V1\Classroom;

use App\Http\Controllers\Api\V1\ApiController;
use App\Models\ClassMaterial;
use App\Models\ClassParticipant;
use App\Models\ClassSession;
use App\Services\Classroom\ClassNotifier;
use App\Services\Classroom\ClassroomException;
use App\Services\Classroom\ClassroomService;
use App\Services\Classroom\ClassScheduleService;
use App\Services\Classroom\MaterialService;
use App\Services\Classroom\PracticeLockService;
use App\Services\Classroom\SchoolService;
use Illuminate\Http\Request;

/**
 * One meeting of a class: preparing it, running it, and closing it.
 *
 * The coach's half of the live classroom. The learner's half is
 * {@see ClassroomController}, kept apart because the two have almost nothing in
 * common: this one writes, that one reads and answers.
 */
class ClassSessionController extends ApiController
{
    public function __construct(
        private readonly ClassroomService $classroom,
        private readonly PracticeLockService $locks,
        private readonly ClassNotifier $notifier,
        private readonly SchoolService $schools,
        private readonly MaterialService $materials,
    ) {}

    /** The coach's own diary. */
    public function index(Request $request)
    {
        $sessions = ClassSession::with(['group.school'])
            ->withCount('materials')
            ->where('coach_id', $request->user()->id)
            ->when($request->filled('from'), fn ($q) => $q->where('starts_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->where('starts_at', '<=', $request->date('to')))
            ->when(! $request->filled('from'), fn ($q) => $q->where('starts_at', '>=', now()->subDay()))
            ->orderBy('starts_at')
            ->limit(100)
            ->get();

        return $this->ok($sessions->map(fn (ClassSession $s) => $this->present($s)));
    }

    /** A one-off class outside the timetable. */
    public function store(Request $request)
    {
        $data = $request->validate([
            'class_group_id' => ['required', 'integer', 'exists:class_groups,id'],
            'title' => ['nullable', 'string', 'max:160'],
            'agenda' => ['nullable', 'string', 'max:4000'],
            'starts_at' => ['required', 'date'],
            'duration_minutes' => ['required', 'integer', 'min:10', 'max:480'],
        ]);

        $group = \App\Models\ClassGroup::findOrFail($data['class_group_id']);
        $this->assertCoachOrManager($request, $group->coach_id, $group->school);

        $starts = \Carbon\CarbonImmutable::parse($data['starts_at']);

        $session = ClassSession::create([
            'class_group_id' => $group->id,
            'coach_id' => $group->coach_id,
            'title' => $data['title'] ?? null,
            'agenda' => $data['agenda'] ?? null,
            'starts_at' => $starts,
            'ends_at' => $starts->addMinutes($data['duration_minutes']),
            'status' => ClassSession::SCHEDULED,
            'room_name' => ClassScheduleService::roomName(),
        ]);

        return $this->created($this->present($session->fresh('group.school')));
    }

    public function show(Request $request, ClassSession $session)
    {
        $this->assertCoach($request, $session);

        $session->load(['group.school', 'materials.media', 'participants.user']);

        return $this->ok($this->present($session) + [
            'agenda' => $session->agenda,
            'materials' => $session->materials->map(fn (ClassMaterial $m) => $this->presentMaterial($m)),
            'participants' => $session->participants->map(
                fn (ClassParticipant $p) => $this->classroom->participantPayload($p)
            ),
            'roll' => $session->group?->students()->get()->map(fn ($s) => [
                'id' => $s->id, 'name' => $s->name,
            ]),
            'concepts_taught' => count($this->locks->conceptsTaughtIn($session)),
        ]);
    }

    public function update(Request $request, ClassSession $session)
    {
        $this->assertCoach($request, $session);

        $session->update($request->validate([
            'title' => ['sometimes', 'nullable', 'string', 'max:160'],
            'agenda' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'starts_at' => ['sometimes', 'date'],
            'ends_at' => ['sometimes', 'date', 'after:starts_at'],
        ]));

        return $this->ok($this->present($session->fresh('group.school')));
    }

    public function cancel(Request $request, ClassSession $session)
    {
        $this->assertCoach($request, $session);
        $session->update(['status' => ClassSession::CANCELLED]);

        return $this->ok(['cancelled' => true]);
    }

    // ----------------------------------------------------------- the material

    /**
     * Add something to teach from.
     *
     * A file is uploaded here and becomes a media asset; a lesson or an
     * exercise is referenced by id and stays where it is. Both end up as one
     * kind of thing on the coach's shelf, which is what lets them share a PDF
     * and a corpus lesson with the same gesture.
     */
    public function addMaterial(Request $request, ClassSession $session)
    {
        $this->assertCoach($request, $session);

        $data = $request->validate([
            'kind' => ['required', 'string', 'in:'.implode(',', ClassMaterial::KINDS)],
            'title' => ['required', 'string', 'max:200'],
            'body' => ['nullable', 'string', 'max:20000'],
            'lesson_id' => ['nullable', 'integer', 'exists:lessons,id'],
            'exercise_id' => ['nullable', 'integer', 'exists:exercises,id'],
            'media_asset_id' => ['nullable', 'integer', 'exists:media_assets,id'],
            'file' => ['nullable', 'file', 'max:204800'],   // 200 MB
            'position' => ['nullable', 'integer', 'min:0', 'max:999'],
        ]);

        $material = $this->materials->add(
            $session,
            $request->user(),
            $data,
            $request->file('file'),
        );

        return $this->created($this->presentMaterial($material->fresh('media')));
    }

    public function removeMaterial(Request $request, ClassSession $session, ClassMaterial $material)
    {
        $this->assertCoach($request, $session);
        $this->assertMaterialBelongs($session, $material);

        $material->delete();

        return $this->ok(['deleted' => true]);
    }

    public function reorderMaterials(Request $request, ClassSession $session)
    {
        $this->assertCoach($request, $session);

        $data = $request->validate([
            'order' => ['required', 'array'],
            'order.*' => ['integer'],
        ]);

        foreach ($data['order'] as $position => $id) {
            $session->materials()->whereKey($id)->update(['position' => $position]);
        }

        return $this->ok(['reordered' => true]);
    }

    // -------------------------------------------------------------- the class

    public function start(Request $request, ClassSession $session)
    {
        $this->assertCoach($request, $session);

        $session = $this->classroom->start($session);
        $join = $this->classroom->join($session, $request->user(), asCoach: true);

        // Everyone on the roll is told the door is open, whatever their
        // reminder setting says: this is not a reminder, it is the class.
        $told = $this->notifier->announce($session, isLiveNow: true);

        return $this->ok([
            'session' => $this->present($session->fresh('group.school')),
            'room' => $join['token']->toArray(),
            'room_available' => $join['room_available'],
            'notified' => $told,
        ]);
    }

    /** Summon a few learners rather than the whole roll. */
    public function summon(Request $request, ClassSession $session)
    {
        $this->assertCoach($request, $session);

        $data = $request->validate([
            'user_ids' => ['required', 'array', 'min:1'],
            'user_ids.*' => ['integer'],
        ]);

        return $this->ok([
            'notified' => $this->notifier->announce($session, $data['user_ids'], isLiveNow: true),
        ]);
    }

    public function end(Request $request, ClassSession $session)
    {
        $this->assertCoach($request, $session);

        return $this->ok($this->present($this->classroom->end($session)->fresh('group.school')));
    }

    /** Attendance, once the class is over. */
    public function attendance(Request $request, ClassSession $session)
    {
        $this->assertCoach($request, $session);

        $participants = $session->participants()->with('user')->get()->keyBy('user_id');

        return $this->ok($session->group->students()->get()->map(fn ($student) => [
            'user_id' => $student->id,
            'name' => $student->name,
            'attended' => $participants->has($student->id),
            'seconds_present' => $participants->get($student->id)?->seconds_present ?? 0,
            'first_joined_at' => $participants->get($student->id)?->first_joined_at?->toIso8601String(),
        ]));
    }

    // ------------------------------------------------------------ authorising

    private function assertCoach(Request $request, ClassSession $session): void
    {
        $user = $request->user();

        if ($session->coach_id === $user->id) {
            return;
        }
        if ($this->schools->isManager($session->group->school, $user)) {
            return;
        }

        throw new ClassroomException('This class is not yours to run.', 403);
    }

    private function assertCoachOrManager(Request $request, int $coachId, $school): void
    {
        $user = $request->user();

        if ($coachId === $user->id || $this->schools->isManager($school, $user)) {
            return;
        }

        throw new ClassroomException('This class is not yours.', 403);
    }

    private function assertMaterialBelongs(ClassSession $session, ClassMaterial $material): void
    {
        if ($material->class_session_id !== $session->id) {
            throw new ClassroomException('That material belongs to another class.', 404);
        }
    }

    private function present(ClassSession $session): array
    {
        return [
            'id' => $session->id,
            'class_group_id' => $session->class_group_id,
            'group' => $session->group?->title,
            'school' => $session->group?->school?->name,
            'coach_id' => $session->coach_id,
            'title' => $session->title ?: $session->group?->title,
            'starts_at' => $session->starts_at?->toIso8601String(),
            'ends_at' => $session->ends_at?->toIso8601String(),
            'status' => $session->status,
            'is_joinable' => $session->isJoinable(),
            'started_at' => $session->started_at?->toIso8601String(),
            'material_count' => $session->materials_count ?? null,
        ];
    }

    private function presentMaterial(ClassMaterial $material): array
    {
        return $this->materials->present($material);
    }
}
