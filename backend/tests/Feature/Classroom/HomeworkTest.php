<?php

namespace Tests\Feature\Classroom;

use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\CefrLevel;
use App\Models\Course;
use App\Models\CourseVersion;
use App\Models\Exercise;
use App\Models\ExerciseOption;
use App\Models\ExerciseTemplate;
use App\Models\Language;
use App\Models\Lesson;
use App\Models\Module;
use App\Models\Skill;
use App\Models\Unit;
use App\Models\WritingAttempt;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Homework: setting it, handing it in, and getting it back.
 *
 * The line these tests keep coming back to is the one between marked and
 * returned. A coach marks, then decides to give it back, so a class's scores
 * never appear half-finished while the coach is still working through the pile
 * - and a learner is never shown a number no person chose to show them.
 */
class HomeworkTest extends ClassroomTestCase
{
    public function test_the_coach_sets_a_piece_of_writing(): void
    {
        $response = $this->actingAs($this->coach)
            ->postJson("/api/v1/classes/{$this->group->id}/homework", [
                'kind' => 'writing',
                'title' => 'یک پاراگراف دربارهٔ تعطیلات',
                'brief' => 'حدود ۱۰۰ کلمه، زمان گذشته.',
                'due_at' => now()->addDays(3)->toIso8601String(),
            ])
            ->assertCreated();

        $this->assertFalse($response->json('data.is_published'));
        $this->assertFalse($response->json('data.accepts_work'));
    }

    /** Unpublished homework is the coach's draft, and nobody else's business. */
    public function test_a_learner_does_not_see_a_draft(): void
    {
        $assignment = $this->assignment();

        $this->actingAs($this->student)
            ->getJson("/api/v1/classes/{$this->group->id}/homework")
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAs($this->student)
            ->getJson("/api/v1/homework/{$assignment->id}")
            ->assertStatus(404);
    }

    /**
     * Publishing writes a row for every learner on the roll.
     *
     * That is what lets a coach see who has not begun, which is the question
     * they actually open the screen to ask.
     */
    public function test_publishing_sets_it_for_everybody_including_those_who_do_nothing(): void
    {
        $assignment = $this->assignment();

        $this->actingAs($this->coach)
            ->postJson("/api/v1/homework/{$assignment->id}/publish")
            ->assertOk()
            ->assertJsonPath('data.is_published', true);

        $this->assertSame(2, AssignmentSubmission::where('assignment_id', $assignment->id)->count());

        $results = $this->actingAs($this->coach)
            ->getJson("/api/v1/homework/{$assignment->id}")->assertOk();

        $this->assertSame(2, $results->json('data.results.set_for'));
        $this->assertSame(2, $results->json('data.results.not_started'));
        $this->assertSame(0, $results->json('data.results.handed_in'));
    }

    public function test_publishing_tells_the_class(): void
    {
        $assignment = $this->assignment();
        $this->actingAs($this->coach)->postJson("/api/v1/homework/{$assignment->id}/publish");

        $this->assertDatabaseHas('notifications', ['notifiable_id' => $this->student->id]);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $this->otherStudent->id]);
    }

    public function test_a_learner_hands_in_a_paragraph(): void
    {
        $assignment = $this->published();

        $this->actingAs($this->student)
            ->postJson("/api/v1/homework/{$assignment->id}/submit", [
                'body' => 'Last summer I went to Isfahan with my family.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', AssignmentSubmission::SUBMITTED);

        $submission = AssignmentSubmission::where('assignment_id', $assignment->id)
            ->where('user_id', $this->student->id)->firstOrFail();

        // Reused rather than copied: a written piece of homework is a writing
        // attempt, marked by the analyser the product already has.
        $this->assertNotNull($submission->writing_attempt_id);
        $this->assertDatabaseHas('writing_attempts', [
            'id' => $submission->writing_attempt_id,
            'user_id' => $this->student->id,
            'text_confirmed' => true,
        ]);
    }

    public function test_homework_takes_photographs_and_recordings(): void
    {
        Storage::fake(config('filesystems.default'));

        $assignment = $this->published(Assignment::UPLOAD);

        $response = $this->actingAs($this->student)
            ->postJson("/api/v1/homework/{$assignment->id}/submit", [
                'body' => 'عکس صفحهٔ دفترم',
                'files' => [UploadedFile::fake()->image('page.jpg')],
            ])
            ->assertOk();

        $this->assertSame(1, count($response->json('data.attachments') ?? []));
    }

    public function test_somebody_from_another_class_cannot_hand_in(): void
    {
        $assignment = $this->published();

        $this->actingAs($this->outsider)
            ->postJson("/api/v1/homework/{$assignment->id}/submit", ['body' => 'سلام'])
            ->assertStatus(403);
    }

    /** Somebody else's writing marked as this learner's would be a forgery. */
    public function test_a_learner_cannot_hand_in_somebody_elses_work(): void
    {
        $assignment = $this->published();

        $theirs = WritingAttempt::create([
            'user_id' => $this->otherStudent->id,
            'source' => 'typed',
            'text' => 'Their paragraph.',
            'text_confirmed' => true,
            'status' => 'pending',
        ]);

        $this->actingAs($this->student)
            ->postJson("/api/v1/homework/{$assignment->id}/submit", [
                'writing_attempt_id' => $theirs->id,
            ])
            ->assertStatus(403);
    }

    // ------------------------------------------------------------- deadlines

    /**
     * A due date a learner cannot miss is a deadline nobody believes; one that
     * slams shut at midnight loses work somebody did.
     */
    public function test_late_work_is_taken_and_marked_late(): void
    {
        $assignment = $this->published();
        $assignment->update(['due_at' => now()->subHour()]);

        $this->actingAs($this->student)
            ->postJson("/api/v1/homework/{$assignment->id}/submit", ['body' => 'دیر شد ولی نوشتم.'])
            ->assertOk()
            ->assertJsonPath('data.is_late', true);
    }

    public function test_late_work_stops_being_taken_eventually(): void
    {
        $assignment = $this->published();
        $assignment->update(['due_at' => now()->subDays(5)]);

        $this->actingAs($this->student)
            ->postJson("/api/v1/homework/{$assignment->id}/submit", ['body' => 'خیلی دیر'])
            ->assertStatus(422);
    }

    public function test_a_coach_can_refuse_late_work_outright(): void
    {
        $assignment = $this->published();
        $assignment->update(['due_at' => now()->subMinute(), 'allow_late' => false]);

        $this->actingAs($this->student)
            ->postJson("/api/v1/homework/{$assignment->id}/submit", ['body' => 'یک دقیقه دیر'])
            ->assertStatus(422);
    }

    // -------------------------------------------------------------- marking

    public function test_a_set_of_exercises_is_marked_by_arithmetic(): void
    {
        [$assignment, $items] = $this->exerciseAssignment();

        $this->actingAs($this->student)
            ->postJson("/api/v1/homework/{$assignment->id}/submit", [
                'responses' => [
                    $items[0]->id => ['selected_options' => [0]],   // right
                    $items[1]->id => ['selected_options' => [1]],   // wrong
                ],
            ])
            ->assertOk();

        $submission = AssignmentSubmission::where('assignment_id', $assignment->id)
            ->where('user_id', $this->student->id)->firstOrFail();

        $this->assertSame(AssignmentSubmission::MARKED, $submission->status);
        $this->assertSame(50.0, (float) $submission->ai_score);
        $this->assertSame(1, $submission->ai_feedback['correct']);
    }

    /** Leaving a question blank is an answer a coach needs to see counted. */
    public function test_an_unanswered_question_is_wrong_and_not_unmarked(): void
    {
        [$assignment, $items] = $this->exerciseAssignment();

        $this->actingAs($this->student)
            ->postJson("/api/v1/homework/{$assignment->id}/submit", [
                'responses' => [$items[0]->id => ['selected_options' => [0]]],
            ]);

        $submission = AssignmentSubmission::where('assignment_id', $assignment->id)
            ->where('user_id', $this->student->id)->firstOrFail();

        $this->assertSame(50.0, (float) $submission->ai_score);
        $this->assertSame([2], $submission->ai_feedback['wrong_positions']);
    }

    /**
     * The line the whole design turns on.
     *
     * A machine's mark is a suggestion sitting next to the coach's column, and
     * a learner sees nothing until somebody chose to show them.
     */
    public function test_a_marked_score_is_not_a_returned_one(): void
    {
        [$assignment] = $this->exerciseAssignment();

        $this->actingAs($this->student)
            ->postJson("/api/v1/homework/{$assignment->id}/submit", ['responses' => []]);

        $learnerView = $this->actingAs($this->student)
            ->getJson("/api/v1/homework/{$assignment->id}")->assertOk();

        $this->assertSame(AssignmentSubmission::MARKED, $learnerView->json('data.mine.status'));
        $this->assertArrayNotHasKey('score', $learnerView->json('data.mine'));
        $this->assertArrayNotHasKey('ai_score', $learnerView->json('data.mine'));

        $coachView = $this->actingAs($this->coach)
            ->getJson("/api/v1/homework/{$assignment->id}")->assertOk();
        $this->assertNotNull($coachView->json('data.submissions.0.ai_score'));
    }

    public function test_the_coach_returns_it_with_their_own_score(): void
    {
        [$assignment] = $this->exerciseAssignment();

        $this->actingAs($this->student)
            ->postJson("/api/v1/homework/{$assignment->id}/submit", ['responses' => []]);

        $submission = AssignmentSubmission::where('assignment_id', $assignment->id)
            ->where('user_id', $this->student->id)->firstOrFail();

        $this->actingAs($this->coach)
            ->postJson("/api/v1/homework/{$assignment->id}/submissions/{$submission->id}/mark", [
                'score' => 70,
                'feedback' => 'دفعهٔ بعد به سوم‌شخص دقت کن.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', AssignmentSubmission::RETURNED);

        $learnerView = $this->actingAs($this->student)
            ->getJson("/api/v1/homework/{$assignment->id}")->assertOk();

        $this->assertSame(70.0, (float) $learnerView->json('data.mine.score'));
        $this->assertSame('دفعهٔ بعد به سوم‌شخص دقت کن.', $learnerView->json('data.mine.feedback'));
    }

    public function test_returning_it_tells_the_learner(): void
    {
        [$assignment] = $this->exerciseAssignment();
        $this->actingAs($this->student)->postJson("/api/v1/homework/{$assignment->id}/submit", ['responses' => []]);

        DB::table('notifications')->delete();

        $submission = AssignmentSubmission::where('assignment_id', $assignment->id)
            ->where('user_id', $this->student->id)->firstOrFail();

        $this->actingAs($this->coach)
            ->postJson("/api/v1/homework/{$assignment->id}/submissions/{$submission->id}/mark");

        $this->assertDatabaseHas('notifications', ['notifiable_id' => $this->student->id]);
    }

    /** Arithmetic can speak for itself, when the coach says so. */
    public function test_auto_release_hands_back_a_marked_exercise_set(): void
    {
        [$assignment] = $this->exerciseAssignment(autoRelease: true);

        $this->actingAs($this->student)
            ->postJson("/api/v1/homework/{$assignment->id}/submit", ['responses' => []]);

        $learnerView = $this->actingAs($this->student)
            ->getJson("/api/v1/homework/{$assignment->id}")->assertOk();

        $this->assertSame(AssignmentSubmission::RETURNED, $learnerView->json('data.mine.status'));
        $this->assertSame(0.0, (float) $learnerView->json('data.mine.score'));
    }

    public function test_a_learner_cannot_mark_anything(): void
    {
        [$assignment] = $this->exerciseAssignment();
        $this->actingAs($this->student)->postJson("/api/v1/homework/{$assignment->id}/submit", ['responses' => []]);

        $submission = AssignmentSubmission::where('assignment_id', $assignment->id)
            ->where('user_id', $this->student->id)->firstOrFail();

        $this->actingAs($this->student)
            ->postJson("/api/v1/homework/{$assignment->id}/submissions/{$submission->id}/mark", ['score' => 100])
            ->assertStatus(403);
    }

    /** A learner must not be handed the answers with the questions. */
    public function test_the_answers_are_not_in_the_learners_copy(): void
    {
        [$assignment] = $this->exerciseAssignment();

        $learnerView = $this->actingAs($this->student)
            ->getJson("/api/v1/homework/{$assignment->id}")->assertOk();

        $this->assertNotNull($learnerView->json('data.items.0.options'));
        $this->assertArrayNotHasKey('correct_options', $learnerView->json('data.items.0'));

        $coachView = $this->actingAs($this->coach)
            ->getJson("/api/v1/homework/{$assignment->id}")->assertOk();
        $this->assertNotNull($coachView->json('data.items.0.correct_options'));
    }

    public function test_a_set_of_exercises_with_no_questions_is_not_set(): void
    {
        $assignment = $this->assignment(Assignment::EXERCISES);

        $this->actingAs($this->coach)
            ->postJson("/api/v1/homework/{$assignment->id}/publish")
            ->assertStatus(422);
    }

    public function test_the_learner_sees_everything_they_owe(): void
    {
        $this->published();

        $this->actingAs($this->student)
            ->getJson('/api/v1/my/homework')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.submission.status', AssignmentSubmission::ASSIGNED);
    }

    // ------------------------------------------------------------- helpers

    private function assignment(string $kind = Assignment::WRITING): Assignment
    {
        $response = $this->actingAs($this->coach)
            ->postJson("/api/v1/classes/{$this->group->id}/homework", [
                'kind' => $kind,
                'title' => 'مشق این هفته',
                'due_at' => now()->addDays(3)->toIso8601String(),
            ])->assertCreated();

        return Assignment::findOrFail($response->json('data.id'));
    }

    private function published(string $kind = Assignment::WRITING): Assignment
    {
        $assignment = $this->assignment($kind);
        $this->actingAs($this->coach)->postJson("/api/v1/homework/{$assignment->id}/publish");

        return $assignment->refresh();
    }

    /** @return array{0: Assignment, 1: Collection} */
    private function exerciseAssignment(bool $autoRelease = false): array
    {
        $lesson = $this->makeLesson();

        $response = $this->actingAs($this->coach)
            ->postJson("/api/v1/classes/{$this->group->id}/homework", [
                'kind' => Assignment::EXERCISES,
                'title' => 'بیست تمرین',
                'points' => 100,
                'auto_release' => $autoRelease,
                'items' => [
                    ['exercise_id' => $this->makeExercise($lesson, 'went')->id],
                    ['exercise_id' => $this->makeExercise($lesson, 'saw')->id],
                ],
            ])->assertCreated();

        $assignment = Assignment::findOrFail($response->json('data.id'));
        $this->actingAs($this->coach)->postJson("/api/v1/homework/{$assignment->id}/publish");

        return [$assignment->refresh(), $assignment->items];
    }

    private function makeLesson(): Lesson
    {
        $level = CefrLevel::where('code', 'B1')->firstOrFail();
        $language = Language::where('code', 'en')->firstOrFail();

        $course = Course::create([
            'language_id' => $language->id,
            'title' => 'Homework course',
            'slug' => 'homework-'.uniqid(),
            'from_cefr_level_id' => $level->id,
            'to_cefr_level_id' => $level->id,
            'is_active' => true,
        ]);
        $version = CourseVersion::create([
            'course_id' => $course->id, 'version' => 1, 'status' => 'published', 'published_at' => now(),
        ]);
        $module = Module::create([
            'course_version_id' => $version->id, 'title' => 'Past simple', 'position' => 0,
        ]);
        $unit = Unit::create(['module_id' => $module->id, 'title' => 'Past simple', 'position' => 1]);

        return Lesson::create([
            'unit_id' => $unit->id, 'title' => 'Irregular verbs',
            'position' => 1, 'status' => 'published', 'difficulty' => 0.0,
        ]);
    }

    private function makeExercise(Lesson $lesson, string $right): Exercise
    {
        $exercise = Exercise::create([
            'exercise_template_id' => ExerciseTemplate::where('code', 'multiple_choice')->value('id'),
            'language_id' => Language::where('code', 'en')->value('id'),
            'lesson_id' => $lesson->id,
            'skill_id' => Skill::first()?->id,
            'cefr_level_id' => CefrLevel::where('code', 'B1')->value('id'),
            'stem' => 'Yesterday I ___ .',
            'difficulty' => 0.0,
            'status' => 'published',
        ]);

        foreach ([[$right, true], ['wrongone', false]] as $position => [$text, $correct]) {
            ExerciseOption::create([
                'exercise_id' => $exercise->id,
                'position' => $position,
                'text' => $text,
                'is_correct' => $correct,
            ]);
        }

        return $exercise;
    }
}
