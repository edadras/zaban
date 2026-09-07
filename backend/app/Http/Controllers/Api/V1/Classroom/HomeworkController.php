<?php

namespace App\Http\Controllers\Api\V1\Classroom;

use App\Http\Controllers\Api\V1\ApiController;
use App\Models\Assignment;
use App\Models\AssignmentItem;
use App\Models\AssignmentSubmission;
use App\Models\ClassAttachment;
use App\Models\ClassGroup;
use App\Models\SpeechAttempt;
use App\Models\User;
use App\Models\WritingAttempt;
use App\Services\Classroom\AttachmentService;
use App\Services\Classroom\ClassroomException;
use App\Services\Classroom\HomeworkService;
use Illuminate\Http\Request;

/**
 * Homework: setting it, handing it in, and giving it back.
 *
 * The one asymmetry worth knowing about is what a score means on each side. A
 * coach sees the machine's mark and their own; a learner sees neither until
 * the coach releases it, because a score somebody has seen is one somebody
 * chose to show them.
 */
class HomeworkController extends ApiController
{
    public function __construct(
        private readonly HomeworkService $homework,
        private readonly AttachmentService $attachments,
    ) {}

    // ---------------------------------------------------------- the coach

    public function index(Request $request, ClassGroup $group)
    {
        $user = $request->user();

        if (! $this->homework->canSee($group, $user)) {
            throw new ClassroomException('This class is not yours.', 403);
        }

        $isCoach = $this->homework->canSet($group, $user);

        $assignments = Assignment::withCount('items')
            ->where('class_group_id', $group->id)
            // Homework that has not been set yet is the coach's draft.
            ->when(! $isCoach, fn ($q) => $q->whereNotNull('published_at'))
            ->orderByDesc('due_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        $mine = $isCoach
            ? collect()
            : AssignmentSubmission::with('attachments.media')
                ->where('user_id', $user->id)
                ->whereIn('assignment_id', $assignments->pluck('id'))
                ->get()
                ->keyBy('assignment_id');

        return $this->ok($assignments->map(fn (Assignment $a) => $this->present($a) + (
            $isCoach
                ? ['results' => $this->homework->results($a)]
                : ['mine' => $this->presentSubmission($mine->get($a->id), forLearner: true)]
        )), ['can_set' => $isCoach]);
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
            'class_session_id' => ['nullable', 'integer', 'exists:class_sessions,id'],
            'settings' => ['nullable', 'array'],
            // Items may be sent with the assignment, which is how "twenty
            // questions from today's lesson" is one gesture rather than
            // twenty-one.
            'items' => ['nullable', 'array', 'max:60'],
            'items.*.exercise_id' => ['nullable', 'integer', 'exists:exercises,id'],
            'items.*.prompt' => ['nullable', 'string', 'max:2000'],
            'items.*.options' => ['nullable', 'array', 'max:8'],
            'items.*.correct_options' => ['nullable', 'array'],
            'items.*.points' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $assignment = $this->homework->create($group, $request->user(), $data);

        foreach ($data['items'] ?? [] as $position => $item) {
            $this->homework->addItem($assignment, $item + ['position' => $position]);
        }

        return $this->created($this->present($assignment->refresh()->loadCount('items')));
    }

    public function show(Request $request, Assignment $assignment)
    {
        $user = $request->user();
        $group = $assignment->group;

        if (! $this->homework->canSee($group, $user)) {
            throw new ClassroomException('This class is not yours.', 403);
        }

        $isCoach = $this->homework->canSet($group, $user);

        if (! $isCoach && ! $assignment->isPublished()) {
            throw new ClassroomException('This homework has not been set yet.', 404);
        }

        $payload = $this->present($assignment->loadCount('items')) + [
            'items' => $assignment->items->map(
                fn (AssignmentItem $i) => $this->presentItem($i, $isCoach)
            ),
        ];

        if ($isCoach) {
            return $this->ok($payload + [
                'results' => $this->homework->results($assignment),
                'submissions' => $assignment->submissions()
                    ->with(['learner:id,name', 'attachments.media'])->get()
                    ->map(fn (AssignmentSubmission $s) => $this->presentSubmission($s, forLearner: false)),
            ]);
        }

        return $this->ok($payload + [
            'mine' => $this->presentSubmission(
                $this->homework->submissionFor($assignment, $user)->load('attachments.media'),
                forLearner: true,
            ),
        ]);
    }

    public function update(Request $request, Assignment $assignment)
    {
        $this->homework->assertCanSet($assignment->group, $request->user());

        $assignment->update($request->validate([
            'title' => ['sometimes', 'string', 'max:200'],
            'brief' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'due_at' => ['sometimes', 'nullable', 'date'],
            'points' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'allow_late' => ['sometimes', 'boolean'],
            'auto_release' => ['sometimes', 'boolean'],
        ]));

        return $this->ok($this->present($assignment->loadCount('items')));
    }

    public function destroy(Request $request, Assignment $assignment)
    {
        $this->homework->assertCanSet($assignment->group, $request->user());

        $assignment->delete();

        return $this->ok(['deleted' => true]);
    }

    public function addItem(Request $request, Assignment $assignment)
    {
        $this->homework->assertCanSet($assignment->group, $request->user());

        $data = $request->validate([
            'exercise_id' => ['nullable', 'integer', 'exists:exercises,id'],
            'prompt' => ['nullable', 'string', 'max:2000'],
            'options' => ['nullable', 'array', 'max:8'],
            'options.*' => ['string', 'max:300'],
            'correct_options' => ['nullable', 'array'],
            'points' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return $this->created($this->presentItem($this->homework->addItem($assignment, $data), true));
    }

    public function removeItem(Request $request, Assignment $assignment, AssignmentItem $item)
    {
        $this->homework->assertCanSet($assignment->group, $request->user());

        if ($item->assignment_id !== $assignment->id) {
            throw new ClassroomException('That question belongs to another assignment.', 404);
        }

        $item->delete();

        return $this->ok(['deleted' => true]);
    }

    public function publish(Request $request, Assignment $assignment)
    {
        return $this->ok($this->present(
            $this->homework->publish($assignment, $request->user())->loadCount('items')
        ));
    }

    // ------------------------------------------------------- the learner

    public function submit(Request $request, Assignment $assignment)
    {
        $data = $request->validate([
            'body' => ['nullable', 'string', 'max:40000'],
            'writing_attempt_id' => ['nullable', 'integer', 'exists:writing_attempts,id'],
            'speech_attempt_id' => ['nullable', 'integer', 'exists:speech_attempts,id'],
            'responses' => ['nullable', 'array'],
            'responses.*.body' => ['nullable', 'string', 'max:4000'],
            'responses.*.selected_options' => ['nullable', 'array'],
            'files' => ['nullable', 'array', 'max:6'],
            'files.*' => ['file', 'max:'.AttachmentService::MAX_KILOBYTES],
        ]);

        $user = $request->user();

        // An attempt handed in with somebody else's id would be somebody
        // else's homework marked as this learner's.
        $this->assertOwnAttempts($user, $data);

        $submission = $this->homework->submit(
            $assignment,
            $user,
            $data,
            $request->file('files', []),
        );

        return $this->ok($this->presentSubmission($submission, forLearner: true));
    }

    // -------------------------------------------------------- the marking

    public function submission(Request $request, Assignment $assignment, AssignmentSubmission $submission)
    {
        $this->homework->assertCanSet($assignment->group, $request->user());
        $this->assertBelongs($assignment, $submission);

        $submission->load(['learner:id,name', 'responses', 'attachments.media', 'writingAttempt', 'speechAttempt']);

        return $this->ok($this->presentSubmission($submission, forLearner: false) + [
            'responses' => $submission->responses->map(fn ($r) => [
                'assignment_item_id' => $r->assignment_item_id,
                'body' => $r->body,
                'selected_options' => $r->selected_options,
                'is_correct' => $r->is_correct,
                'score' => $r->score,
            ]),
        ]);
    }

    public function mark(Request $request, Assignment $assignment, AssignmentSubmission $submission)
    {
        $this->homework->assertCanSet($assignment->group, $request->user());
        $this->assertBelongs($assignment, $submission);

        $data = $request->validate([
            'score' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'feedback' => ['nullable', 'string', 'max:20000'],
        ]);

        return $this->ok($this->presentSubmission(
            $this->homework->release(
                $submission,
                $request->user(),
                isset($data['score']) ? (float) $data['score'] : null,
                $data['feedback'] ?? null,
            ),
            forLearner: false,
        ));
    }

    public function releaseAll(Request $request, Assignment $assignment)
    {
        return $this->ok([
            'returned' => $this->homework->releaseAll($assignment, $request->user()),
        ]);
    }

    /** Everything a learner has been set, across all their classes. */
    public function mine(Request $request)
    {
        $submissions = AssignmentSubmission::with(['assignment.group:id,title', 'attachments.media'])
            ->where('user_id', $request->user()->id)
            ->whereHas('assignment', fn ($q) => $q->whereNotNull('published_at'))
            ->get()
            ->sortBy(fn (AssignmentSubmission $s) => $s->assignment->due_at?->timestamp ?? PHP_INT_MAX)
            ->values();

        return $this->ok($submissions->map(fn (AssignmentSubmission $s) => [
            'assignment' => $this->present($s->assignment),
            'submission' => $this->presentSubmission($s, forLearner: true),
        ]));
    }

    // ------------------------------------------------------------ presenting

    private function present(Assignment $assignment): array
    {
        return [
            'id' => $assignment->id,
            'class_group_id' => $assignment->class_group_id,
            'class' => $assignment->group?->title,
            'kind' => $assignment->kind,
            'title' => $assignment->title,
            'brief' => $assignment->brief,
            'lesson_id' => $assignment->lesson_id,
            'due_at' => $assignment->due_at?->toIso8601String(),
            'published_at' => $assignment->published_at?->toIso8601String(),
            'is_published' => $assignment->isPublished(),
            'is_overdue' => $assignment->isOverdue(),
            'accepts_work' => $assignment->acceptsWorkNow(),
            'points' => $assignment->points,
            'allow_late' => $assignment->allow_late,
            'auto_release' => $assignment->auto_release,
            'item_count' => $assignment->items_count ?? $assignment->items()->count(),
            'settings' => $assignment->settings,
        ];
    }

    /** The coach sees the machine's mark; a learner sees the released one. */
    private function presentSubmission(?AssignmentSubmission $submission, bool $forLearner): ?array
    {
        if ($submission === null) {
            return null;
        }

        $payload = [
            'id' => $submission->id,
            'assignment_id' => $submission->assignment_id,
            'user_id' => $submission->user_id,
            'learner' => $submission->learner?->name,
            'status' => $submission->status,
            'is_late' => $submission->is_late,
            'submitted_at' => $submission->submitted_at?->toIso8601String(),
            'returned_at' => $submission->returned_at?->toIso8601String(),
            'body' => $submission->body,
            // Loaded by every caller: an empty list here would read as "there
            // are none" rather than "nobody asked".
            'attachments' => $submission->loadMissing('attachments.media')->attachments
                ->map(fn (ClassAttachment $a) => $this->attachments->present($a)),
        ];

        if ($forLearner) {
            // A score a learner sees is one somebody chose to show them.
            return $payload + ($submission->isReturned() ? [
                'score' => $submission->score,
                'feedback' => $submission->feedback,
                'ai_feedback' => $submission->ai_feedback,
            ] : []);
        }

        return $payload + [
            'score' => $submission->score,
            'feedback' => $submission->feedback,
            'ai_score' => $submission->ai_score,
            'ai_feedback' => $submission->ai_feedback,
            'ai_model' => $submission->ai_model,
            'ai_error' => $submission->ai_error,
            'ai_marked_at' => $submission->ai_marked_at?->toIso8601String(),
            'marked_by' => $submission->marker?->name,
        ];
    }

    private function presentItem(AssignmentItem $item, bool $forCoach): array
    {
        $payload = [
            'id' => $item->id,
            'exercise_id' => $item->exercise_id,
            'prompt' => $item->prompt,
            'options' => $item->options,
            'points' => $item->points,
            'position' => $item->position,
        ];

        // A learner must not be handed the answers with the questions.
        return $forCoach ? $payload + ['correct_options' => $item->correct_options] : $payload;
    }

    // ------------------------------------------------------------ guarding

    private function assertBelongs(Assignment $assignment, AssignmentSubmission $submission): void
    {
        if ($submission->assignment_id !== $assignment->id) {
            throw new ClassroomException('That submission belongs to another assignment.', 404);
        }
    }

    /** @param  array{writing_attempt_id?:?int,speech_attempt_id?:?int}  $data */
    private function assertOwnAttempts(User $user, array $data): void
    {
        if (! empty($data['writing_attempt_id'])) {
            $owns = WritingAttempt::whereKey($data['writing_attempt_id'])
                ->where('user_id', $user->id)->exists();

            if (! $owns) {
                throw new ClassroomException('That piece of writing is not yours.', 403);
            }
        }

        if (! empty($data['speech_attempt_id'])) {
            $owns = SpeechAttempt::whereKey($data['speech_attempt_id'])
                ->where('user_id', $user->id)->exists();

            if (! $owns) {
                throw new ClassroomException('That recording is not yours.', 403);
            }
        }
    }
}
