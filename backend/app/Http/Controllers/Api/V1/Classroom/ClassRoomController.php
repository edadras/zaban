<?php

namespace App\Http\Controllers\Api\V1\Classroom;

use App\Events\Classroom\ClassroomEvent;
use App\Http\Controllers\Api\V1\ApiController;
use App\Models\ClassAnswer;
use App\Models\ClassMaterial;
use App\Models\ClassParticipant;
use App\Models\ClassQuestion;
use App\Models\ClassSession;
use App\Models\Exercise;
use App\Models\PracticeLock;
use App\Services\Classroom\ClassroomException;
use App\Services\Classroom\ClassroomService;
use App\Services\Classroom\PracticeLockService;
use App\Services\Classroom\SchoolService;
use Illuminate\Http\Request;

/**
 * Inside the room, while the class is running.
 *
 * Both sides use this: the coach to hand out microphones, share material and
 * ask questions; the learner to join, raise a hand and answer. Which side
 * somebody is on is decided per action rather than per route, because the room
 * is one thing and splitting it in two would mean two views of the same state.
 */
class ClassRoomController extends ApiController
{
    public function __construct(
        private readonly ClassroomService $classroom,
        private readonly PracticeLockService $locks,
        private readonly SchoolService $schools,
    ) {}

    /**
     * Everything needed to render the room, in one call.
     *
     * One request rather than five: a learner opening a class on a slow
     * connection should see the room, not a cascade of spinners.
     */
    public function show(Request $request, ClassSession $session)
    {
        $this->assertInvited($request, $session);

        $session->load(['group.school', 'coach', 'participants.user', 'materials.media']);
        $isCoach = $this->isCoach($request, $session);

        $shared = $session->materials->firstWhere('shared_at', '!=', null);

        return $this->ok([
            'session' => [
                'id' => $session->id,
                'title' => $session->title ?: $session->group?->title,
                'coach' => $session->coach?->name,
                'coach_id' => $session->coach_id,
                'status' => $session->status,
                'starts_at' => $session->starts_at?->toIso8601String(),
                'ends_at' => $session->ends_at?->toIso8601String(),
                'is_joinable' => $session->isJoinable(),
            ],
            'is_coach' => $isCoach,
            'channel' => "class-session.{$session->id}",
            'participants' => $session->participants->map(
                fn (ClassParticipant $p) => $this->classroom->participantPayload($p)
            ),
            // A learner sees only what has been put on screen. The coach's
            // shelf is the coach's.
            'materials' => ($isCoach ? $session->materials : $session->materials->whereNotNull('shared_at'))
                ->values()
                ->map(fn (ClassMaterial $m) => $this->presentMaterial($m)),
            'shared_material_id' => $shared?->id,
            'open_question' => $this->openQuestionFor($session, $request->user()->id, $isCoach),
        ]);
    }

    public function join(Request $request, ClassSession $session)
    {
        $this->assertInvited($request, $session);

        $result = $this->classroom->join(
            $session,
            $request->user(),
            asCoach: $this->isCoach($request, $session),
        );

        return $this->ok([
            'participant' => $this->classroom->participantPayload($result['participant']->load('user')),
            'room' => $result['token']->toArray(),
            'room_available' => $result['room_available'],
            'channel' => "class-session.{$session->id}",
        ]);
    }

    public function leave(Request $request, ClassSession $session)
    {
        $this->classroom->leave($session, $request->user());

        return $this->ok(['left' => true]);
    }

    /** A fresh room key, for a client whose token is ageing out mid-class. */
    public function token(Request $request, ClassSession $session)
    {
        $this->assertInvited($request, $session);

        return $this->ok($this->classroom->tokenFor($session, $request->user())->toArray());
    }

    public function raiseHand(Request $request, ClassSession $session)
    {
        $this->assertInvited($request, $session);

        $this->classroom->raiseHand(
            $session,
            $request->user(),
            $request->boolean('raised', true),
        );

        return $this->ok(['raised' => $request->boolean('raised', true)]);
    }

    // ------------------------------------------------- the coach's controls

    public function setMedia(Request $request, ClassSession $session, ClassParticipant $participant)
    {
        $this->assertCoach($request, $session);
        $this->assertParticipantBelongs($session, $participant);

        $data = $request->validate([
            'audio' => ['nullable', 'boolean'],
            'video' => ['nullable', 'boolean'],
        ]);

        if (! array_key_exists('audio', $data) && ! array_key_exists('video', $data)) {
            throw new ClassroomException('Nothing to change.');
        }

        $updated = $this->classroom->setMedia(
            $session,
            $participant,
            $data['audio'] ?? null,
            $data['video'] ?? null,
        );

        return $this->ok($this->classroom->participantPayload($updated->load('user')));
    }

    public function muteAll(Request $request, ClassSession $session)
    {
        $this->assertCoach($request, $session);

        return $this->ok(['muted' => $this->classroom->muteEveryone($session)]);
    }

    public function removeParticipant(Request $request, ClassSession $session, ClassParticipant $participant)
    {
        $this->assertCoach($request, $session);
        $this->assertParticipantBelongs($session, $participant);

        if ($participant->user_id === $session->coach_id) {
            throw new ClassroomException('The coach cannot be removed from their own class.');
        }

        $this->classroom->removeFromRoom($session, $participant);

        return $this->ok(['removed' => true]);
    }

    public function shareMaterial(Request $request, ClassSession $session, ClassMaterial $material)
    {
        $this->assertCoach($request, $session);

        if ($material->class_session_id !== $session->id) {
            throw new ClassroomException('That material belongs to another class.', 404);
        }

        return $this->ok($this->presentMaterial($this->classroom->shareMaterial($session, $material)));
    }

    public function closeMaterial(Request $request, ClassSession $session, ClassMaterial $material)
    {
        $this->assertCoach($request, $session);
        $this->classroom->closeMaterial($session, $material);

        return $this->ok(['closed' => true]);
    }

    // ----------------------------------------------------------- questions

    public function askQuestion(Request $request, ClassSession $session)
    {
        $this->assertCoach($request, $session);

        $data = $request->validate([
            'kind' => ['required', 'string', 'in:open,poll,exercise'],
            'prompt' => ['required_without:exercise_id', 'nullable', 'string', 'max:2000'],
            'options' => ['nullable', 'array', 'max:8'],
            'options.*' => ['string', 'max:300'],
            'correct_options' => ['nullable', 'array'],
            'exercise_id' => ['nullable', 'integer', 'exists:exercises,id'],
            'class_material_id' => ['nullable', 'integer', 'exists:class_materials,id'],
            'user_ids' => ['nullable', 'array'],
            'user_ids.*' => ['integer'],
        ]);

        // An exercise from the corpus brings its own wording, so the coach does
        // not retype a question the system already holds.
        $prompt = $data['prompt'] ?? null;
        if ($prompt === null && ! empty($data['exercise_id'])) {
            $prompt = (string) (Exercise::find($data['exercise_id'])?->prompt ?? 'Answer the exercise.');
        }

        $question = $session->questions()->create([
            'asked_by' => $request->user()->id,
            'exercise_id' => $data['exercise_id'] ?? null,
            'class_material_id' => $data['class_material_id'] ?? null,
            'kind' => $data['kind'],
            'prompt' => $prompt,
            'options' => $data['options'] ?? null,
            'correct_options' => $data['correct_options'] ?? null,
            'addressed_user_ids' => $data['user_ids'] ?? null,
            'opened_at' => now(),
        ]);

        // Only the questions still open are closed, and only the others: a coach
        // asking a second question means the first is over.
        $session->questions()->whereKeyNot($question->id)
            ->whereNull('closed_at')->update(['closed_at' => now()]);

        event(new ClassroomEvent($session->id, ClassroomEvent::QUESTION_OPENED, [
            'question' => $this->presentQuestion($question, forCoach: false),
        ]));

        return $this->created($this->presentQuestion($question, forCoach: true));
    }

    public function answerQuestion(Request $request, ClassSession $session, ClassQuestion $question)
    {
        $this->assertInvited($request, $session);

        if ($question->class_session_id !== $session->id) {
            throw new ClassroomException('That question belongs to another class.', 404);
        }
        if ($question->closed_at !== null) {
            throw new ClassroomException('That question is closed.');
        }
        if (! $question->addresses($request->user()->id)) {
            throw new ClassroomException('That question was not put to you.', 403);
        }

        $data = $request->validate([
            'body' => ['nullable', 'string', 'max:4000'],
            'selected_options' => ['nullable', 'array'],
        ]);

        // Marked only when the coach said what right looks like. An open
        // question has no correct answer and must not pretend otherwise.
        $isCorrect = null;
        if ($question->correct_options !== null && isset($data['selected_options'])) {
            $isCorrect = collect($question->correct_options)->sort()->values()->all()
                === collect($data['selected_options'])->sort()->values()->all();
        }

        $answer = ClassAnswer::updateOrCreate(
            ['class_question_id' => $question->id, 'user_id' => $request->user()->id],
            [
                'body' => $data['body'] ?? null,
                'selected_options' => $data['selected_options'] ?? null,
                'is_correct' => $isCorrect,
                'answered_at' => now(),
            ],
        );

        event(new ClassroomEvent($session->id, ClassroomEvent::ANSWER_RECEIVED, [
            'question_id' => $question->id,
            'user_id' => $request->user()->id,
            'is_correct' => $isCorrect,
        ]));

        return $this->ok(['id' => $answer->id, 'is_correct' => $isCorrect]);
    }

    public function closeQuestion(Request $request, ClassSession $session, ClassQuestion $question)
    {
        $this->assertCoach($request, $session);

        $question->update(['closed_at' => now()]);
        $question->load('answers.user');

        event(new ClassroomEvent($session->id, ClassroomEvent::QUESTION_CLOSED, [
            'question_id' => $question->id,
        ]));

        return $this->ok($this->presentQuestion($question, forCoach: true));
    }

    public function questionResults(Request $request, ClassSession $session, ClassQuestion $question)
    {
        $this->assertCoach($request, $session);
        $question->load('answers.user');

        return $this->ok($this->presentQuestion($question, forCoach: true));
    }

    // ------------------------------------------------------- practice lock

    public function lockPractice(Request $request, ClassSession $session)
    {
        $this->assertCoach($request, $session);

        $data = $request->validate([
            'user_ids' => ['nullable', 'array'],
            'user_ids.*' => ['integer'],
            'concept_ids' => ['nullable', 'array'],
            'concept_ids.*' => ['integer'],
            'hours' => ['nullable', 'integer', 'min:1', 'max:168'],
            'note' => ['nullable', 'string', 'max:300'],
        ]);

        $locks = $this->locks->lock(
            $session,
            $request->user(),
            $data['user_ids'] ?? [],
            $data['concept_ids'] ?? null,
            $data['hours'] ?? null,
            $data['note'] ?? null,
        );

        return $this->created([
            'locked' => $locks->count(),
            'expires_at' => $locks->first()?->expires_at?->toIso8601String(),
            'concept_count' => count($locks->first()?->concept_ids ?? []),
        ]);
    }

    public function releasePractice(Request $request, ClassSession $session)
    {
        $this->assertCoach($request, $session);

        $data = $request->validate([
            'user_ids' => ['nullable', 'array'],
            'user_ids.*' => ['integer'],
        ]);

        return $this->ok(['released' => $this->locks->release($session, $data['user_ids'] ?? [])]);
    }

    /** What a lock would cover, before the coach commits to it. */
    public function lockPreview(Request $request, ClassSession $session)
    {
        $this->assertCoach($request, $session);

        $conceptIds = $this->locks->conceptsTaughtIn($session);

        return $this->ok([
            'concept_count' => count($conceptIds),
            'concepts' => \App\Models\Concept::whereIn('id', array_slice($conceptIds, 0, 40))
                ->pluck('label'),
            'student_count' => $session->group?->students()->count() ?? 0,
        ]);
    }

    // ------------------------------------------------------------ authorising

    private function isCoach(Request $request, ClassSession $session): bool
    {
        return $session->coach_id === $request->user()->id
            || $this->schools->isManager($session->group->school, $request->user());
    }

    private function assertCoach(Request $request, ClassSession $session): void
    {
        if (! $this->isCoach($request, $session)) {
            throw new ClassroomException('Only the coach can do that.', 403);
        }
    }

    /** On the roll, or running the class. Nobody else sees the room at all. */
    private function assertInvited(Request $request, ClassSession $session): void
    {
        if ($this->isCoach($request, $session)) {
            return;
        }
        if ($session->group?->students()->whereKey($request->user()->id)->exists()) {
            return;
        }

        throw new ClassroomException('You are not in this class.', 403);
    }

    private function assertParticipantBelongs(ClassSession $session, ClassParticipant $participant): void
    {
        if ($participant->class_session_id !== $session->id) {
            throw new ClassroomException('That person is not in this class.', 404);
        }
    }

    /** @return array<string, mixed>|null */
    private function openQuestionFor(ClassSession $session, int $userId, bool $isCoach): ?array
    {
        $question = $session->questions()->whereNull('closed_at')->latest('opened_at')->first();

        if ($question === null) {
            return null;
        }
        if (! $isCoach && ! $question->addresses($userId)) {
            return null;
        }

        return $this->presentQuestion($question->load('answers'), $isCoach);
    }

    /**
     * A question, with the answers only when the coach is asking.
     *
     * A learner must not be able to read the room's answers - or the correct
     * one - out of the payload that shows them the question.
     */
    private function presentQuestion(ClassQuestion $question, bool $forCoach): array
    {
        $payload = [
            'id' => $question->id,
            'kind' => $question->kind,
            'prompt' => $question->prompt,
            'options' => $question->options,
            'exercise_id' => $question->exercise_id,
            'addressed_user_ids' => $question->addressed_user_ids,
            'opened_at' => $question->opened_at?->toIso8601String(),
            'closed_at' => $question->closed_at?->toIso8601String(),
        ];

        if (! $forCoach) {
            return $payload;
        }

        return $payload + [
            'correct_options' => $question->correct_options,
            'answers' => $question->answers->map(fn (ClassAnswer $a) => [
                'user_id' => $a->user_id,
                'name' => $a->user?->name,
                'body' => $a->body,
                'selected_options' => $a->selected_options,
                'is_correct' => $a->is_correct,
                'answered_at' => $a->answered_at?->toIso8601String(),
            ]),
        ];
    }

    private function presentMaterial(ClassMaterial $material): array
    {
        return [
            'id' => $material->id,
            'kind' => $material->kind,
            'title' => $material->title,
            'body' => $material->body,
            'media_asset_id' => $material->media_asset_id,
            'mime' => $material->media?->mime,
            'lesson_id' => $material->lesson_id,
            'exercise_id' => $material->exercise_id,
            'position' => $material->position,
            'is_shared' => $material->shared_at !== null,
        ];
    }
}
