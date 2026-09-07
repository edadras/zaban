<?php

namespace App\Http\Controllers\Panel;

use App\Models\ClassGroup;
use App\Models\ClassMaterial;
use App\Models\ClassSession;
use App\Models\Lesson;
use App\Services\Classroom\ClassNotifier;
use App\Services\Classroom\ClassroomService;
use App\Services\Classroom\ClassScheduleService;
use App\Services\Classroom\MaterialService;
use App\Services\Classroom\RecordingService;
use App\Support\PanelAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Preparing one meeting of a class, and opening the door to it.
 *
 * The prep page is the whole reason a coach wants a website rather than a
 * phone: uploading a video, pasting a text and picking a lesson out of the
 * corpus are all things done with a keyboard and a file manager.
 */
class ClassSessionController extends PanelController
{
    public function __construct(
        PanelAccess $access,
        private readonly ClassroomService $classroom,
        private readonly MaterialService $materials,
        private readonly ClassNotifier $notifier,
        private readonly RecordingService $recordings,
    ) {
        parent::__construct($access);
    }

    public function index(Request $request)
    {
        $user = $this->me();

        $sessions = ClassSession::with(['group.school'])
            ->withCount(['materials', 'participants'])
            ->where('coach_id', $user->id)
            ->where('starts_at', '>=', $request->date('from') ?? now()->subWeek())
            ->orderBy('starts_at')
            ->limit(200)
            ->get();

        return view('panel.sessions.index', ['sessions' => $sessions]);
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

        $group = ClassGroup::findOrFail($data['class_group_id']);
        $this->allow($this->access->canManageGroup($this->me(), $group));

        $starts = Carbon::parse($data['starts_at'], $group->timezone);

        $session = ClassSession::create([
            'class_group_id' => $group->id,
            'coach_id' => $group->coach_id,
            'title' => $data['title'] ?? null,
            'agenda' => $data['agenda'] ?? null,
            'starts_at' => $starts,
            'ends_at' => $starts->copy()->addMinutes($data['duration_minutes']),
            'status' => ClassSession::SCHEDULED,
            'room_name' => ClassScheduleService::roomName(),
        ]);

        return redirect()->route('panel.sessions.show', $session)->with('status', 'جلسه ساخته شد.');
    }

    public function show(Request $request, ClassSession $session)
    {
        $session->load(['group.school', 'group.students', 'coach', 'materials.media', 'materials.lesson']);
        $this->allow($this->access->canRunSession($this->me(), $session));

        $search = trim((string) $request->query('lesson', ''));

        return view('panel.sessions.show', [
            'session' => $session,
            'recording' => $this->recordings->present($session),
            'kinds' => ClassMaterial::KINDS,
            'lessonSearch' => $search,
            'lessonResults' => $search === '' ? collect() : Lesson::with('unit.module')
                ->where('status', 'published')
                ->where('title', 'like', "%{$search}%")
                ->orderBy('title')->limit(25)->get(),
        ]);
    }

    public function update(Request $request, ClassSession $session)
    {
        $this->allow($this->access->canRunSession($this->me(), $session));

        $session->update($request->validate([
            'title' => ['nullable', 'string', 'max:160'],
            'agenda' => ['nullable', 'string', 'max:4000'],
        ]));

        return back()->with('status', 'ذخیره شد.');
    }

    public function cancel(ClassSession $session)
    {
        $this->allow($this->access->canRunSession($this->me(), $session));

        $session->update(['status' => ClassSession::CANCELLED]);

        return redirect()->route('panel.sessions.index')->with('status', 'جلسه لغو شد.');
    }

    // ---------------------------------------------------------- the material

    public function addMaterial(Request $request, ClassSession $session)
    {
        $this->allow($this->access->canRunSession($this->me(), $session));

        $data = $request->validate([
            'kind' => ['required', 'string', 'in:'.implode(',', ClassMaterial::KINDS)],
            'title' => ['required', 'string', 'max:200'],
            'body' => ['nullable', 'string', 'max:20000'],
            'lesson_id' => ['nullable', 'integer', 'exists:lessons,id'],
            'exercise_id' => ['nullable', 'integer', 'exists:exercises,id'],
            'file' => ['nullable', 'file', 'max:204800'],   // 200 MB
        ]);

        return $this->attempt(
            fn () => $this->materials->add($session, $this->me(), $data, $request->file('file')),
            route('panel.sessions.show', $session),
            'به محتوای جلسه افزوده شد.',
        );
    }

    public function removeMaterial(ClassSession $session, ClassMaterial $material)
    {
        $this->allow($this->access->canRunSession($this->me(), $session));
        abort_unless($material->class_session_id === $session->id, 404);

        $material->delete();

        return back()->with('status', 'حذف شد.');
    }

    public function reorderMaterials(Request $request, ClassSession $session)
    {
        $this->allow($this->access->canRunSession($this->me(), $session));

        $data = $request->validate([
            'order' => ['required', 'array'],
            'order.*' => ['integer'],
        ]);

        foreach ($data['order'] as $position => $id) {
            $session->materials()->whereKey($id)->update(['position' => $position]);
        }

        return response()->json(['ok' => true]);
    }

    // ------------------------------------------------------------- the class

    public function start(ClassSession $session)
    {
        $this->allow($this->access->canRunSession($this->me(), $session));

        return $this->attempt(
            function () use ($session) {
                $this->classroom->start($session);
                $this->notifier->announce($session, [], true);
            },
            route('panel.sessions.room', $session),
            'کلاس شروع شد و به زبان‌آموزان اطلاع داده شد.',
        );
    }

    public function summon(Request $request, ClassSession $session)
    {
        $this->allow($this->access->canRunSession($this->me(), $session));

        $ids = array_map('intval', $request->input('user_ids', []));
        $told = $this->notifier->announce($session, $ids, true);

        return back()->with('status', "به {$told} نفر اطلاع داده شد.");
    }

    public function end(ClassSession $session)
    {
        $this->allow($this->access->canRunSession($this->me(), $session));

        $this->classroom->end($session);

        return redirect()->route('panel.sessions.attendance', $session)
            ->with('status', 'کلاس بسته شد.');
    }

    public function attendance(ClassSession $session)
    {
        $this->allow($this->access->canRunSession($this->me(), $session));

        $session->load(['group.school', 'participants.user']);

        return view('panel.sessions.attendance', [
            'session' => $session,
            'recording' => $this->recordings->present($session),
        ]);
    }

    /**
     * Watching a class back.
     *
     * Its own page rather than a player bolted onto the attendance table: a
     * coach reviewing their own teaching and an administrator looking into a
     * complaint both want the video and nothing else.
     */
    public function recording(ClassSession $session)
    {
        $this->allow($this->access->canRunSession($this->me(), $session));

        $session->load(['group.school', 'coach', 'recording']);

        return view('panel.sessions.recording', [
            'session' => $session,
            'recording' => $this->recordings->present($session),
            'playback' => $session->hasRecording()
                ? $this->recordings->playback($session, $this->me())
                : null,
        ]);
    }
}
