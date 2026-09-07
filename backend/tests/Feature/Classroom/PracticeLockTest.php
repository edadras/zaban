<?php

namespace Tests\Feature\Classroom;

use App\Models\ClassMaterial;
use App\Models\ClassSession;
use App\Models\Concept;
use App\Models\Exercise;
use App\Models\Lesson;
use App\Models\PracticeLock;
use App\Models\SessionActivity;
use App\Models\VocabularySense;
use App\Services\Learning\AdaptiveLearningService;
use Illuminate\Support\Facades\DB;

/**
 * The class reaching into the learner's own evening.
 *
 * This is the join between the two halves of the product, and the only place
 * where a person overrules the engine. So what is tested is that the override
 * actually reaches the session composer - not that a row was written.
 */
class PracticeLockTest extends ClassroomTestCase
{
    private Lesson $lesson;

    /** @var list<int> */
    private array $taughtConceptIds = [];

    /** @var list<int> */
    private array $otherConceptIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->lesson = $this->makeLessonWithConcepts('Class material', 3, $this->taughtConceptIds);
        $this->makeLessonWithConcepts('Something else entirely', 3, $this->otherConceptIds);
    }

    public function test_the_coach_locks_the_class_onto_what_was_taught(): void
    {
        $session = $this->sessionTeaching($this->lesson);

        $this->actingAs($this->coach)
            ->postJson("/api/v1/class-sessions/{$session->id}/room/lock", [
                'note' => 'Tonight: the phrasal verbs from today.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.locked', 2);

        $lock = PracticeLock::forUser($this->student->id);

        $this->assertNotNull($lock);
        $this->assertEqualsCanonicalizing($this->taughtConceptIds, $lock->concept_ids);
    }

    /**
     * The point of the whole feature: the evening's practice is the class.
     */
    public function test_a_locked_learners_session_is_built_from_the_class(): void
    {
        $session = $this->sessionTeaching($this->lesson);

        $this->actingAs($this->coach)
            ->postJson("/api/v1/class-sessions/{$session->id}/room/lock");

        $learning = app(AdaptiveLearningService::class)->buildNextSession($this->student->id, 20);

        $conceptIds = SessionActivity::where('learning_session_id', $learning->id)
            ->whereNotNull('concept_id')->pluck('concept_id')->unique()->all();

        $this->assertNotEmpty($conceptIds, 'the locked session still has something to do');

        foreach ($conceptIds as $id) {
            $this->assertContains(
                (int) $id,
                $this->taughtConceptIds,
                'nothing in the session wandered off what the coach taught',
            );
        }

        $this->assertSame($this->lesson->id, $learning->composition['lesson_id']);
        $this->assertNotNull($learning->composition['practice_lock_id']);
    }

    /** A learner nobody locked keeps their own curriculum. */
    public function test_an_unlocked_learner_is_unaffected(): void
    {
        $session = $this->sessionTeaching($this->lesson);

        $this->actingAs($this->coach)
            ->postJson("/api/v1/class-sessions/{$session->id}/room/lock", [
                'user_ids' => [$this->student->id],
            ]);

        $this->assertNull(PracticeLock::forUser($this->otherStudent->id));

        $learning = app(AdaptiveLearningService::class)->buildNextSession($this->otherStudent->id, 20);
        $this->assertNull($learning->composition['practice_lock_id']);
    }

    public function test_the_lock_lets_go_when_it_expires(): void
    {
        $session = $this->sessionTeaching($this->lesson);

        $this->actingAs($this->coach)
            ->postJson("/api/v1/class-sessions/{$session->id}/room/lock", ['hours' => 2]);

        $this->assertNotNull(PracticeLock::forUser($this->student->id));

        $this->travel(3)->hours();

        $this->assertNull(
            PracticeLock::forUser($this->student->id),
            'a coach who forgets to lift a lock does not freeze a curriculum',
        );
    }

    public function test_the_coach_can_lift_it_by_hand(): void
    {
        $session = $this->sessionTeaching($this->lesson);

        $this->actingAs($this->coach)->postJson("/api/v1/class-sessions/{$session->id}/room/lock");
        $this->actingAs($this->coach)
            ->postJson("/api/v1/class-sessions/{$session->id}/room/unlock")
            ->assertOk();

        $this->assertNull(PracticeLock::forUser($this->student->id));
    }

    /** A second class the same day replaces the first rather than fighting it. */
    public function test_a_later_lock_replaces_the_earlier_one(): void
    {
        $first = $this->sessionTeaching($this->lesson);
        $this->actingAs($this->coach)->postJson("/api/v1/class-sessions/{$first->id}/room/lock");

        $secondLesson = Lesson::find($this->makeLessonWithConcepts('Later class', 2, $ids)->id);
        $second = $this->sessionTeaching($secondLesson);
        $this->actingAs($this->coach)->postJson("/api/v1/class-sessions/{$second->id}/room/lock");

        $this->assertSame(
            1,
            PracticeLock::query()->active()->where('user_id', $this->student->id)->count(),
        );
        $this->assertSame($second->id, PracticeLock::forUser($this->student->id)->class_session_id);
    }

    /** A class of nothing but an uploaded video has no concepts to lock onto. */
    public function test_a_class_with_nothing_the_engine_can_teach_is_refused(): void
    {
        $session = $this->makeSession();
        ClassMaterial::create([
            'class_session_id' => $session->id,
            'uploaded_by' => $this->coach->id,
            'kind' => 'video',
            'title' => 'A recording of the whiteboard',
        ]);

        $this->actingAs($this->coach)
            ->postJson("/api/v1/class-sessions/{$session->id}/room/lock")
            ->assertStatus(422);
    }

    public function test_the_learner_is_told_why_their_practice_looks_like_this(): void
    {
        $session = $this->sessionTeaching($this->lesson);

        $this->actingAs($this->coach)
            ->postJson("/api/v1/class-sessions/{$session->id}/room/lock", [
                'note' => 'Revise tonight.',
            ]);

        $this->actingAs($this->student)
            ->getJson('/api/v1/my/classes')
            ->assertOk()
            ->assertJsonPath('data.practice_lock.note', 'Revise tonight.');
    }

    public function test_a_learner_cannot_lock_anybody(): void
    {
        $session = $this->sessionTeaching($this->lesson);

        $this->actingAs($this->student)
            ->postJson("/api/v1/class-sessions/{$session->id}/room/lock")
            ->assertStatus(403);
    }

    // ------------------------------------------------------------- helpers

    private function sessionTeaching(Lesson $lesson): ClassSession
    {
        $session = $this->makeSession();

        ClassMaterial::create([
            'class_session_id' => $session->id,
            'uploaded_by' => $this->coach->id,
            'kind' => 'lesson',
            'title' => $lesson->title,
            'lesson_id' => $lesson->id,
        ]);

        return $session;
    }

    /**
     * A lesson, some concepts, and an answerable exercise for each - the least
     * the adaptive engine needs to be able to build a session at all.
     *
     * @param  list<int>  $conceptIds  filled with the ids created
     */
    private function makeLessonWithConcepts(string $title, int $count, ?array &$conceptIds = null): Lesson
    {
        $level = \App\Models\CefrLevel::where('code', 'B1')->firstOrFail();
        $language = \App\Models\Language::where('code', 'en')->firstOrFail();

        $course = \App\Models\Course::create([
            'language_id' => $language->id,
            'title' => $title.' course',
            'slug' => str($title)->slug().'-'.uniqid(),
            'from_cefr_level_id' => $level->id,
            'to_cefr_level_id' => $level->id,
            'is_active' => true,
        ]);
        $version = \App\Models\CourseVersion::create([
            'course_id' => $course->id, 'version' => 1, 'status' => 'published', 'published_at' => now(),
        ]);
        $module = \App\Models\Module::create([
            'course_version_id' => $version->id, 'title' => $title, 'position' => 0,
        ]);
        $unit = \App\Models\Unit::create([
            'module_id' => $module->id, 'title' => $title, 'position' => 1,
        ]);
        $lesson = Lesson::create([
            'unit_id' => $unit->id,
            'title' => $title,
            'position' => 1,
            'status' => 'published',
            'difficulty' => 0.0,
        ]);

        $conceptIds = [];

        for ($i = 0; $i < $count; $i++) {
            $item = \App\Models\VocabularyItem::create([
                'language_id' => $language->id,
                'headword' => "{$title} word {$i}",
                'normalised' => strtolower("{$title} word {$i}"),
                'cefr_level_id' => $level->id,
            ]);
            $sense = VocabularySense::create([
                'vocabulary_item_id' => $item->id,
                'sense_number' => 1,
                'cefr_level_id' => $level->id,
            ]);
            $concept = Concept::create([
                'conceptable_type' => VocabularySense::class,
                'conceptable_id' => $sense->id,
                'language_id' => $language->id,
                'cefr_level_id' => $level->id,
                'label' => "{$title} word {$i}",
                'difficulty' => 0.0,
                'importance' => 1.0,
                'is_active' => true,
            ]);
            $conceptIds[] = $concept->id;

            DB::table('lesson_concept')->insert([
                'lesson_id' => $lesson->id,
                'concept_id' => $concept->id,
            ]);

            $exercise = Exercise::create([
                'exercise_template_id' => \App\Models\ExerciseTemplate::where('code', 'multiple_choice')->value('id'),
                'language_id' => $language->id,
                'lesson_id' => $lesson->id,
                'skill_id' => \App\Models\Skill::first()?->id,
                'cefr_level_id' => $level->id,
                'stem' => "Which one is \"{$title} word {$i}\"?",
                'difficulty' => 0.0,
                'status' => 'published',
            ]);

            foreach ([['right', true], ['wrong', false]] as $p => [$text, $correct]) {
                \App\Models\ExerciseOption::create([
                    'exercise_id' => $exercise->id,
                    'position' => $p,
                    'text' => $text,
                    'is_correct' => $correct,
                ]);
            }

            DB::table('exercise_concepts')->insert([
                'exercise_id' => $exercise->id,
                'concept_id' => $concept->id,
            ]);
        }

        return $lesson;
    }
}
