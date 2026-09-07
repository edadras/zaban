<?php

namespace App\Services\Classroom;

use App\Events\Classroom\ClassBoardEvent;
use App\Jobs\MarkAssignmentSubmission;
use App\Models\Assignment;
use App\Models\AssignmentItem;
use App\Models\AssignmentResponse;
use App\Models\AssignmentSubmission;
use App\Models\ClassGroup;
use App\Models\Exercise;
use App\Models\User;
use App\Models\WritingAttempt;
use App\Notifications\HomeworkNotice;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Setting homework, handing it in, and giving it back.
 *
 * Three decisions shape the rest of it.
 *
 * A submission row exists from the moment homework is published, not from the
 * moment a learner starts. That is what lets a coach see who has not begun,
 * which is the question they actually open this screen to ask.
 *
 * Marked and returned are different states. The coach marks, then decides to
 * give it back; a score a learner can see is one somebody chose to show them,
 * so a class's marks never appear half-finished while the coach is still
 * working through the pile.
 *
 * And a late deadline is not a closed one. A due date a learner cannot miss is
 * a deadline nobody believes; one that slams shut at midnight loses work
 * somebody did. Late work is taken for a grace period, and marked late.
 */
class HomeworkService
{
    public function __construct(
        private readonly SchoolService $schools,
        private readonly AttachmentService $attachments,
    ) {}

    // ---------------------------------------------------------- who may what

    public function canSet(ClassGroup $group, User $user): bool
    {
        return $group->coach_id === $user->id
            || $this->schools->isManager($group->school, $user);
    }

    public function canSee(ClassGroup $group, User $user): bool
    {
        return $this->canSet($group, $user)
            || $group->students()->whereKey($user->id)->exists();
    }

    public function assertCanSet(ClassGroup $group, User $user): void
    {
        if (! $this->canSet($group, $user)) {
            throw new ClassroomException('Only this class\'s coach can set its homework.', 403);
        }
    }

    // ------------------------------------------------------------- setting

    /**
     * @param  array{kind:string,title:string,brief?:?string,due_at?:?string,points?:?int,allow_late?:bool,auto_release?:bool,settings?:array,lesson_id?:?int,class_session_id?:?int}  $data
     */
    public function create(ClassGroup $group, User $coach, array $data): Assignment
    {
        $this->assertCanSet($group, $coach);

        if (! in_array($data['kind'], Assignment::KINDS, true)) {
            throw new ClassroomException('That is not a kind of homework this system sets.');
        }

        return Assignment::create([
            'class_group_id' => $group->id,
            'class_session_id' => $data['class_session_id'] ?? null,
            'created_by' => $coach->id,
            'kind' => $data['kind'],
            'title' => $data['title'],
            'brief' => $data['brief'] ?? null,
            'lesson_id' => $data['lesson_id'] ?? null,
            'cefr_level_id' => $group->cefr_level_id,
            'due_at' => $data['due_at'] ?? null,
            'points' => $data['points'] ?? 100,
            'allow_late' => $data['allow_late'] ?? true,
            'auto_release' => $data['auto_release'] ?? false,
            'settings' => $data['settings'] ?? null,
        ]);
    }

    /**
     * Add a question.
     *
     * An exercise from the course brings its own wording, options and answer,
     * so the coach does not retype - and cannot mistype - a question the
     * system already holds.
     */
    public function addItem(Assignment $assignment, array $data): AssignmentItem
    {
        $prompt = $data['prompt'] ?? null;
        $options = $data['options'] ?? null;
        $correct = $data['correct_options'] ?? null;

        if (! empty($data['exercise_id'])) {
            $exercise = Exercise::with('options')->find($data['exercise_id']);

            if ($exercise !== null) {
                $prompt ??= (string) $exercise->stem;

                if ($options === null) {
                    [$options, $correct] = $this->optionsOf($exercise);
                }
            }
        }

        if ($prompt === null && empty($data['exercise_id'])) {
            throw new ClassroomException('A question needs either its own wording or an exercise.');
        }

        return $assignment->items()->create([
            'exercise_id' => $data['exercise_id'] ?? null,
            'prompt' => $prompt,
            'options' => $options,
            'correct_options' => $correct,
            'points' => $data['points'] ?? 1,
            'position' => $data['position'] ?? ((int) $assignment->items()->max('position') + 1),
        ]);
    }

    /**
     * Set it for the class.
     *
     * Every learner on the roll gets a row now rather than when they start, so
     * "who has not begun" is a question the coach's screen can answer.
     */
    public function publish(Assignment $assignment, User $by): Assignment
    {
        $this->assertCanSet($assignment->group, $by);

        if ($assignment->kind === Assignment::EXERCISES && $assignment->items()->count() === 0) {
            throw new ClassroomException('This homework has no questions in it yet.');
        }

        DB::transaction(function () use ($assignment) {
            $assignment->update(['published_at' => $assignment->published_at ?? now()]);

            foreach ($assignment->group->students()->pluck('users.id') as $learnerId) {
                AssignmentSubmission::firstOrCreate(
                    ['assignment_id' => $assignment->id, 'user_id' => $learnerId],
                    ['status' => AssignmentSubmission::ASSIGNED],
                );
            }
        });

        foreach ($assignment->group->students()->get() as $learner) {
            $learner->notify(new HomeworkNotice($assignment, 'homework.set'));
        }

        event(new ClassBoardEvent($assignment->class_group_id, ClassBoardEvent::ASSIGNMENT_POSTED, [
            'assignment_id' => $assignment->id,
        ]));

        return $assignment->refresh();
    }

    // ------------------------------------------------------------ handing in

    public function submissionFor(Assignment $assignment, User $learner): AssignmentSubmission
    {
        if (! $assignment->group->students()->whereKey($learner->id)->exists()) {
            throw new ClassroomException('This homework was not set for you.', 403);
        }

        return AssignmentSubmission::firstOrCreate(
            ['assignment_id' => $assignment->id, 'user_id' => $learner->id],
            ['status' => AssignmentSubmission::ASSIGNED],
        );
    }

    /**
     * Hand it in.
     *
     * @param  array{body?:?string,responses?:array<int,array>,writing_attempt_id?:?int,speech_attempt_id?:?int}  $data
     * @param  list<UploadedFile>  $files
     */
    public function submit(Assignment $assignment, User $learner, array $data, array $files = []): AssignmentSubmission
    {
        if (! $assignment->acceptsWorkNow()) {
            throw new ClassroomException(
                $assignment->isPublished()
                    ? 'This homework is closed.'
                    : 'This homework has not been set yet.',
            );
        }

        $submission = $this->submissionFor($assignment, $learner);

        if ($submission->isReturned()) {
            throw new ClassroomException('This has already been marked and given back.');
        }

        DB::transaction(function () use ($assignment, $submission, $data, $learner) {
            $submission->fill([
                'body' => $data['body'] ?? $submission->body,
                'speech_attempt_id' => $data['speech_attempt_id'] ?? $submission->speech_attempt_id,
                'writing_attempt_id' => $data['writing_attempt_id'] ?? $submission->writing_attempt_id,
                'status' => AssignmentSubmission::SUBMITTED,
                'submitted_at' => now(),
                'is_late' => $assignment->isOverdue(),
            ]);

            // A written piece of homework is a writing attempt, marked by the
            // analyser the product already has rather than by a second one.
            if ($assignment->kind === Assignment::WRITING
                && $submission->writing_attempt_id === null
                && filled($data['body'] ?? null)) {
                $submission->writing_attempt_id = $this->writingAttemptFor($assignment, $learner, $data['body'])->id;
            }

            $submission->save();

            foreach ($data['responses'] ?? [] as $itemId => $answer) {
                $this->recordResponse($assignment, $submission, (int) $itemId, $answer);
            }
        });

        $this->attachments->attachMany($submission, $learner, $files);

        // The machine takes a first pass; the coach still decides.
        if ($assignment->canBeMarkedByMachine()) {
            MarkAssignmentSubmission::dispatch($submission->id);
        }

        return $submission->refresh()->load('attachments.media');
    }

    /**
     * Give it back.
     *
     * The coach's score wins over the machine's whenever they gave one, and
     * `marked_by` records which of them the learner is looking at.
     */
    public function release(
        AssignmentSubmission $submission,
        User $coach,
        ?float $score = null,
        ?string $feedback = null,
    ): AssignmentSubmission {
        $this->assertCanSet($submission->assignment->group, $coach);

        $submission->forceFill([
            'score' => $score ?? $submission->score ?? $submission->ai_score,
            'feedback' => $feedback ?? $submission->feedback,
            'marked_by' => $coach->id,
            'marked_at' => now(),
            'returned_at' => now(),
            'status' => AssignmentSubmission::RETURNED,
        ])->save();

        $submission->learner?->notify(new HomeworkNotice($submission->assignment, 'homework.returned', [
            'score' => $submission->score,
            'points' => $submission->assignment->points,
        ]));

        event(new ClassBoardEvent(
            $submission->assignment->class_group_id,
            ClassBoardEvent::SUBMISSION_MARKED,
            ['assignment_id' => $submission->assignment_id, 'submission_id' => $submission->id],
        ));

        return $submission;
    }

    /** Give the whole pile back at once, once the coach has been through it. */
    public function releaseAll(Assignment $assignment, User $coach): int
    {
        $this->assertCanSet($assignment->group, $coach);

        $ready = $assignment->submissions()
            ->whereIn('status', [AssignmentSubmission::MARKED, AssignmentSubmission::SUBMITTED])
            ->get();

        foreach ($ready as $submission) {
            $this->release($submission, $coach);
        }

        return $ready->count();
    }

    // ------------------------------------------------------------- results

    /**
     * What the class did with it.
     *
     * Deliberately includes the learners who did nothing: an average over the
     * people who handed in tells a coach the opposite of what they need to
     * know.
     *
     * @return array<string, mixed>
     */
    public function results(Assignment $assignment): array
    {
        $submissions = $assignment->submissions()->with('learner:id,name')->get();
        $handedIn = $submissions->filter(fn (AssignmentSubmission $s) => $s->isHandedIn());
        $scored = $handedIn->whereNotNull('score');

        return [
            'set_for' => $submissions->count(),
            'handed_in' => $handedIn->count(),
            'not_started' => $submissions->where('status', AssignmentSubmission::ASSIGNED)->count(),
            'late' => $handedIn->where('is_late', true)->count(),
            'returned' => $submissions->where('status', AssignmentSubmission::RETURNED)->count(),
            'awaiting_marking' => $handedIn->whereIn('status', [
                AssignmentSubmission::SUBMITTED, AssignmentSubmission::MARKING,
            ])->count(),
            'average_score' => $scored->isEmpty()
                ? null
                : round($scored->avg('score'), 1),
        ];
    }

    // ------------------------------------------------------------- private

    private function recordResponse(
        Assignment $assignment,
        AssignmentSubmission $submission,
        int $itemId,
        array $answer,
    ): void {
        $item = $assignment->items()->whereKey($itemId)->first();

        if ($item === null) {
            return;
        }

        AssignmentResponse::updateOrCreate(
            [
                'assignment_submission_id' => $submission->id,
                'assignment_item_id' => $item->id,
            ],
            [
                'body' => $answer['body'] ?? null,
                'selected_options' => $answer['selected_options'] ?? null,
            ],
        );
    }

    private function writingAttemptFor(Assignment $assignment, User $learner, string $text): WritingAttempt
    {
        return WritingAttempt::create([
            'user_id' => $learner->id,
            'lesson_id' => $assignment->lesson_id,
            'cefr_level_id' => $assignment->cefr_level_id,
            'source' => WritingAttempt::SOURCE_TYPED,
            'text' => trim($text),
            'word_count' => str_word_count($text),
            // Typed text is the learner's own words by definition.
            'text_confirmed' => true,
            'status' => WritingAttempt::STATUS_PENDING,
        ]);
    }

    /** @return array{0: list<string>, 1: list<int>} */
    private function optionsOf(Exercise $exercise): array
    {
        $ordered = $exercise->options->sortBy('position')->values();

        $texts = [];
        $correct = [];

        foreach ($ordered as $index => $option) {
            $texts[] = (string) $option->text;

            if ($option->is_correct) {
                $correct[] = $index;
            }
        }

        return [$texts, $texts === [] ? [] : $correct];
    }
}
