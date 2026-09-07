<?php

namespace Tests\Feature\Classroom;

use App\Models\CefrLevel;
use App\Models\ClassMaterial;
use App\Models\ClassSession;
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

/**
 * Asking the room a question the system already holds.
 *
 * The point is that the coach does not retype it. The wording, the options and
 * the right answer come from the corpus, so the room marks itself and nobody
 * decides twice what right looks like - which is also what stops a coach's
 * transcription error from marking a correct answer wrong.
 */
class CorpusQuestionTest extends ClassroomTestCase
{
    private ClassSession $session;

    private Exercise $exercise;

    protected function setUp(): void
    {
        parent::setUp();

        $this->session = $this->makeSession();
        $lesson = $this->makeLesson();
        $this->exercise = $this->makeExercise($lesson);

        ClassMaterial::create([
            'class_session_id' => $this->session->id,
            'uploaded_by' => $this->coach->id,
            'kind' => 'lesson',
            'title' => $lesson->title,
            'lesson_id' => $lesson->id,
        ]);

        $this->actingAs($this->coach)->postJson("/api/v1/class-sessions/{$this->session->id}/start");
    }

    public function test_the_coach_is_offered_what_todays_material_can_ask(): void
    {
        $response = $this->actingAs($this->coach)
            ->getJson("/api/v1/class-sessions/{$this->session->id}/room/askable")
            ->assertOk();

        $this->assertSame($this->exercise->id, $response->json('data.0.id'));
        $this->assertSame(['went', 'goed'], $response->json('data.0.options'));
        $this->assertSame([0], $response->json('data.0.correct_options'));
    }

    /** A class with nothing from the corpus on its shelf offers nothing. */
    public function test_a_session_teaching_only_an_upload_has_nothing_to_offer(): void
    {
        $other = $this->makeSession();
        ClassMaterial::create([
            'class_session_id' => $other->id,
            'uploaded_by' => $this->coach->id,
            'kind' => 'video',
            'title' => 'A recording of the whiteboard',
        ]);

        $this->actingAs($this->coach)
            ->getJson("/api/v1/class-sessions/{$other->id}/room/askable")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_asking_by_id_brings_the_wording_and_the_options(): void
    {
        $response = $this->actingAs($this->coach)
            ->postJson("/api/v1/class-sessions/{$this->session->id}/room/questions", [
                'kind' => 'exercise',
                'exercise_id' => $this->exercise->id,
            ])
            ->assertCreated();

        $this->assertSame($this->exercise->stem, $response->json('data.prompt'));
        $this->assertSame(['went', 'goed'], $response->json('data.options'));
        $this->assertSame([0], $response->json('data.correct_options'));
    }

    /** And the room marks itself. */
    public function test_the_answer_is_marked_against_the_corpus(): void
    {
        $question = $this->actingAs($this->coach)
            ->postJson("/api/v1/class-sessions/{$this->session->id}/room/questions", [
                'kind' => 'exercise',
                'exercise_id' => $this->exercise->id,
            ])->json('data.id');

        $this->actingAs($this->student)
            ->postJson("/api/v1/class-sessions/{$this->session->id}/room/questions/{$question}/answer", [
                'selected_options' => [0],
            ])
            ->assertOk()
            ->assertJsonPath('data.is_correct', true);

        $this->actingAs($this->otherStudent)
            ->postJson("/api/v1/class-sessions/{$this->session->id}/room/questions/{$question}/answer", [
                'selected_options' => [1],
            ])
            ->assertOk()
            ->assertJsonPath('data.is_correct', false);
    }

    /** A learner must not be handed the answer with the question. */
    public function test_the_learner_does_not_see_the_right_answer(): void
    {
        $this->actingAs($this->coach)
            ->postJson("/api/v1/class-sessions/{$this->session->id}/room/questions", [
                'kind' => 'exercise',
                'exercise_id' => $this->exercise->id,
            ]);

        $this->actingAs($this->student)->postJson("/api/v1/class-sessions/{$this->session->id}/room/join");

        $room = $this->actingAs($this->student)
            ->getJson("/api/v1/class-sessions/{$this->session->id}/room")
            ->assertOk();

        $this->assertSame(['went', 'goed'], $room->json('data.open_question.options'));
        $this->assertNull($room->json('data.open_question.correct_options'));
        $this->assertNull($room->json('data.open_question.answers'));
    }

    /** Anything the coach types themselves still wins over the corpus. */
    public function test_the_coach_can_override_the_corpus_wording(): void
    {
        $response = $this->actingAs($this->coach)
            ->postJson("/api/v1/class-sessions/{$this->session->id}/room/questions", [
                'kind' => 'exercise',
                'exercise_id' => $this->exercise->id,
                'prompt' => 'In your own words, why?',
            ])
            ->assertCreated();

        $this->assertSame('In your own words, why?', $response->json('data.prompt'));
    }

    public function test_a_learner_cannot_read_the_coachs_question_bank(): void
    {
        $this->actingAs($this->student)
            ->getJson("/api/v1/class-sessions/{$this->session->id}/room/askable")
            ->assertStatus(403);
    }

    // ------------------------------------------------------------- helpers

    private function makeLesson(): Lesson
    {
        $level = CefrLevel::where('code', 'B1')->firstOrFail();
        $language = Language::where('code', 'en')->firstOrFail();

        $course = Course::create([
            'language_id' => $language->id,
            'title' => 'Corpus course',
            'slug' => 'corpus-course-'.uniqid(),
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
        $unit = Unit::create([
            'module_id' => $module->id, 'title' => 'Past simple', 'position' => 1,
        ]);

        return Lesson::create([
            'unit_id' => $unit->id,
            'title' => 'Irregular verbs',
            'position' => 1,
            'status' => 'published',
            'difficulty' => 0.0,
        ]);
    }

    private function makeExercise(Lesson $lesson): Exercise
    {
        $exercise = Exercise::create([
            'exercise_template_id' => ExerciseTemplate::where('code', 'multiple_choice')->value('id'),
            'language_id' => Language::where('code', 'en')->value('id'),
            'lesson_id' => $lesson->id,
            'skill_id' => Skill::first()?->id,
            'cefr_level_id' => CefrLevel::where('code', 'B1')->value('id'),
            'stem' => 'Yesterday I ___ to the market.',
            'difficulty' => 0.0,
            'status' => 'published',
        ]);

        foreach ([['went', true], ['goed', false]] as $position => [$text, $correct]) {
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
