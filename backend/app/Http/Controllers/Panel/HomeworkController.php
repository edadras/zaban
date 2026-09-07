<?php

namespace App\Http\Controllers\Panel;

use App\Models\Assignment;
use App\Models\AssignmentItem;
use App\Models\AssignmentSubmission;
use App\Models\ClassGroup;
use App\Models\Lesson;
use App\Services\Classroom\ClassroomException;
use App\Services\Classroom\HomeworkService;
use App\Support\PanelAccess;
use Illuminate\Http\Request;

/**
 * Homework, from the coach's desk.
 *
 * Setting it, and then the screen they actually live in: who handed in, who
 * did not, what the machine thought, and one place to write a mark and give it
 * back.
 */
class HomeworkController extends PanelController
{
    public function __construct(
        PanelAccess $access,
        private readonly HomeworkService $homework,
    ) {
        parent::__construct($access);
    }

    public function index(ClassGroup $group)
    {
        $this->allow($this->homework->canSee($group, $this->me()));

        $assignments = Assignment::withCount('items')
            ->where('class_group_id', $group->id)
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        return view('panel.homework.index', [
            'group' => $group,
            'assignments' => $assignments,
            'results' => $assignments->mapWithKeys(
                fn (Assignment $a) => [$a->id => $this->homework->results($a)]
            ),
            'kinds' => Assignment::KINDS,
        ]);
    }

    public function store(Request $request, ClassGroup $group)
    {
        $data = $request->validate([
            'kind' => ['required', 'string', 'in:'.implode(',', Assignment::KINDS)],
            'title' => ['required', 'string', 'max:200'],
            'brief' => ['nullable', 'string', 'max:20000'],
            'due_at' => ['nullable', 'date'],
            'points' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'allow_late' => ['nullable', 'boolean'],
            'auto_release' => ['nullable', 'boolean'],
            'lesson_id' => ['nullable', 'integer', 'exists:lessons,id'],
        ]);

        try {
            $assignment = $this->homework->create($group, $this->me(), $data + [
                'allow_late' => $request->boolean('allow_late'),
                'auto_release' => $request->boolean('auto_release'),
            ]);
        } catch (ClassroomException $e) {
            return back()->withInput()->withErrors(['classroom' => $e->getMessage()]);
        }

        return redirect()->route('panel.homework.show', $assignment)
            ->with('status', 'مشق ساخته شد. سؤال‌ها را اضافه کنید و بعد آن را برای کلاس بگذارید.');
    }

    public function show(Request $request, Assignment $assignment)
    {
        $this->allow($this->homework->canSee($assignment->group, $this->me()));

        $assignment->load(['items.exercise', 'group']);
        $search = trim((string) $request->query('lesson', ''));

        return view('panel.homework.show', [
            'assignment' => $assignment,
            'group' => $assignment->group,
            'results' => $this->homework->results($assignment),
            'submissions' => $assignment->submissions()
                ->with('learner:id,name')
                ->get()
                ->sortBy(fn (AssignmentSubmission $s) => $s->learner?->name ?? '')
                ->values(),
            'canSet' => $this->homework->canSet($assignment->group, $this->me()),
            'lessonSearch' => $search,
            'lessonResults' => $search === '' ? collect() : Lesson::with('unit.module')
                ->where('status', 'published')
                ->where('title', 'like', "%{$search}%")
                ->orderBy('title')->limit(25)->get(),
        ]);
    }

    public function addItem(Request $request, Assignment $assignment)
    {
        $this->allow($this->homework->canSet($assignment->group, $this->me()));

        $data = $request->validate([
            'exercise_id' => ['nullable', 'integer', 'exists:exercises,id'],
            'prompt' => ['nullable', 'string', 'max:2000'],
            'options_text' => ['nullable', 'string', 'max:2000'],
            'correct' => ['nullable', 'string', 'max:100'],
            'points' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        // Typed as one option per line, and the correct ones by number: a
        // coach writing a quiz should not be filling in a form per option.
        $options = collect(preg_split('/\R/u', (string) ($data['options_text'] ?? '')))
            ->map(fn ($line) => trim($line))
            ->filter()
            ->values()
            ->all();

        $correct = collect(explode(',', (string) ($data['correct'] ?? '')))
            ->map(fn ($n) => (int) trim($n) - 1)
            ->filter(fn ($n) => $n >= 0)
            ->values()
            ->all();

        return $this->attempt(
            fn () => $this->homework->addItem($assignment, [
                'exercise_id' => $data['exercise_id'] ?? null,
                'prompt' => $data['prompt'] ?? null,
                'options' => $options ?: null,
                'correct_options' => $options && $correct ? $correct : null,
                'points' => $data['points'] ?? 1,
            ]),
            route('panel.homework.show', $assignment),
            'سؤال افزوده شد.',
        );
    }

    public function removeItem(Assignment $assignment, AssignmentItem $item)
    {
        $this->allow($this->homework->canSet($assignment->group, $this->me()));
        abort_unless($item->assignment_id === $assignment->id, 404);

        $item->delete();

        return back()->with('status', 'سؤال حذف شد.');
    }

    public function publish(Assignment $assignment)
    {
        return $this->attempt(
            fn () => $this->homework->publish($assignment, $this->me()),
            route('panel.homework.show', $assignment),
            'مشق برای کلاس گذاشته شد و به زبان‌آموزان اطلاع رفت.',
        );
    }

    /** One learner's work, and the box the coach writes the mark in. */
    public function submission(Assignment $assignment, AssignmentSubmission $submission)
    {
        $this->allow($this->homework->canSet($assignment->group, $this->me()));
        abort_unless($submission->assignment_id === $assignment->id, 404);

        $submission->load([
            'learner:id,name', 'responses.item', 'attachments.media',
            'writingAttempt', 'speechAttempt',
        ]);

        return view('panel.homework.submission', [
            'assignment' => $assignment,
            'submission' => $submission,
            'group' => $assignment->group,
        ]);
    }

    public function mark(Request $request, Assignment $assignment, AssignmentSubmission $submission)
    {
        $this->allow($this->homework->canSet($assignment->group, $this->me()));
        abort_unless($submission->assignment_id === $assignment->id, 404);

        $data = $request->validate([
            'score' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'feedback' => ['nullable', 'string', 'max:20000'],
        ]);

        return $this->attempt(
            fn () => $this->homework->release(
                $submission,
                $this->me(),
                isset($data['score']) ? (float) $data['score'] : null,
                $data['feedback'] ?? null,
            ),
            route('panel.homework.show', $assignment),
            'نمره ثبت شد و به زبان‌آموز برگردانده شد.',
        );
    }

    public function releaseAll(Assignment $assignment)
    {
        return $this->attempt(
            fn () => $this->homework->releaseAll($assignment, $this->me()),
            route('panel.homework.show', $assignment),
            'همهٔ مشق‌های تصحیح‌شده برگردانده شدند.',
        );
    }
}
