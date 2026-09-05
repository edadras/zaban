<?php

namespace Tests\Feature\Learning;

use App\Models\LearnerProfile;
use App\Services\Learning\AdaptiveLearningService;
use App\Services\Learning\SessionShape;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A session used to be an ordered list with no shape. Activities were
 * interleaved so that no two of a kind ran together, which meant it opened on
 * whichever bucket was fullest - for anyone carrying review debt, that was a
 * question about a word the session had not taught yet. Hence the complaint
 * that the learning screen just asks questions.
 *
 * The order below is the claim: meet the material, then use it.
 */
class SessionShapeTest extends TestCase
{
    use RefreshDatabase;

    private int $userId;

    private int $lessonId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        $this->buildCourse();
    }

    public function test_the_session_teaches_before_it_tests(): void
    {
        $session = $this->build();

        $phases = DB::table('session_activities')
            ->where('learning_session_id', $session->id)
            ->orderBy('position')
            ->pluck('phase')
            ->unique()
            ->values()
            ->all();

        $expected = array_values(array_intersect(SessionShape::order(), $phases));

        $this->assertSame($expected, $phases, 'the phases ran out of order');

        $firstStudy = $this->firstPositionOf($session->id, SessionShape::STUDY);
        $firstPractice = $this->firstPositionOf($session->id, SessionShape::PRACTISE);

        $this->assertNotNull($firstStudy, 'a session with a lesson must contain the lesson');
        $this->assertNotNull($firstPractice, 'nothing practised what the lesson taught');
        $this->assertLessThan(
            $firstPractice,
            $firstStudy,
            'the learner was asked about the words before being shown them',
        );
    }

    public function test_the_lesson_text_is_the_first_thing_in_the_study_phase(): void
    {
        $session = $this->build();

        $first = DB::table('session_activities')
            ->where('learning_session_id', $session->id)
            ->where('phase', SessionShape::STUDY)
            ->orderBy('session_activities.position')
            ->join('lesson_blocks', 'lesson_blocks.id', '=', 'session_activities.subject_id')
            ->value('lesson_blocks.type');

        $this->assertSame('source_text', $first, 'the lesson should open with the lesson');
    }

    public function test_practice_asks_about_the_words_the_lesson_just_taught(): void
    {
        $session = $this->build();

        $taught = DB::table('lesson_concept')->where('lesson_id', $this->lessonId)->pluck('concept_id');

        $practised = DB::table('session_activities')
            ->where('learning_session_id', $session->id)
            ->where('phase', SessionShape::PRACTISE)
            ->pluck('concept_id')
            ->filter();

        $this->assertNotEmpty($practised);
        foreach ($practised as $conceptId) {
            $this->assertContains(
                $conceptId,
                $taught->all(),
                'the practice phase reached outside the lesson it follows',
            );
        }
    }

    public function test_every_activity_can_say_why_it_is_there(): void
    {
        $session = $this->build();

        $unexplained = DB::table('session_activities')
            ->where('learning_session_id', $session->id)
            ->where(fn ($q) => $q->whereNull('rationale')->orWhere('rationale', ''))
            ->pluck('activity_type');

        $this->assertSame([], $unexplained->all(), 'these activities appear with no explanation');
    }

    public function test_the_api_describes_the_shape_of_the_session(): void
    {
        $user = \App\Models\User::find($this->userId);

        $response = $this->actingAs($user)->getJson('/api/v1/session/next');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'plan' => [['phase', 'title', 'purpose', 'activities', 'completed', 'estimated_seconds']],
                    'activities' => [['id', 'phase', 'type', 'rationale', 'subject']],
                ],
            ]);

        $plan = $response->json('data.plan');
        $this->assertNotEmpty($plan);

        // Only phases holding work are announced.
        foreach ($plan as $phase) {
            $this->assertGreaterThan(0, $phase['activities'], "{$phase['phase']} was announced but is empty");
            $this->assertNotSame('', $phase['purpose'], "{$phase['phase']} does not say what it is for");
        }
    }

    public function test_a_review_backlog_shifts_the_session_without_removing_the_lesson(): void
    {
        $light = SessionShape::slots(20, 0);
        $heavy = SessionShape::slots(20, 60);

        $this->assertGreaterThan(
            $light[SessionShape::CONSOLIDATE],
            $heavy[SessionShape::CONSOLIDATE],
            'a backlog should pull the session toward review',
        );
        $this->assertGreaterThanOrEqual(
            1,
            $heavy[SessionShape::STUDY],
            'a backlog must never leave a learner with no lesson at all',
        );
    }

    // ------------------------------------------------------------- fixtures

    private function build()
    {
        return app(AdaptiveLearningService::class)->buildNextSession($this->userId, 20);
    }

    private function firstPositionOf(int $sessionId, string $phase): ?int
    {
        $value = DB::table('session_activities')
            ->where('learning_session_id', $sessionId)
            ->where('phase', $phase)
            ->min('position');

        return $value === null ? null : (int) $value;
    }

    private function buildCourse(): void
    {
        $languageId = DB::table('languages')->where('code', 'en')->value('id');
        $levelId = DB::table('cefr_levels')->where('code', 'B1')->value('id');

        $this->userId = DB::table('users')->insertGetId([
            'name' => 'Learner',
            'email' => 'shape@example.test',
            'password' => bcrypt('secret'),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $courseId = DB::table('courses')->insertGetId([
            'slug' => 'book', 'language_id' => $languageId,
            'from_cefr_level_id' => $levelId, 'to_cefr_level_id' => $levelId,
            'title' => 'Book', 'track' => 'general', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $versionId = DB::table('course_versions')->insertGetId([
            'course_id' => $courseId, 'version' => 1, 'status' => 'published',
            'published_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $moduleId = DB::table('modules')->insertGetId([
            'course_version_id' => $versionId, 'title' => 'Module', 'position' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $unitId = DB::table('units')->insertGetId([
            'module_id' => $moduleId, 'title' => 'Unit', 'cefr_level_id' => $levelId,
            'position' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->lessonId = DB::table('lessons')->insertGetId([
            'unit_id' => $unitId, 'title' => 'Jobs', 'cefr_level_id' => $levelId,
            'kind' => 'vocabulary', 'position' => 1, 'status' => 'draft',
            'copyright_status' => 'owned', 'created_at' => now(), 'updated_at' => now(),
        ]);

        // The page, then a card per word: the order the study phase should keep.
        DB::table('lesson_blocks')->insert([
            ['lesson_id' => $this->lessonId, 'type' => 'source_text', 'position' => 0,
                'title' => 'Read', 'config' => json_encode(['text' => 'Body text.']),
                'estimated_seconds' => 90, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $templateId = DB::table('exercise_templates')->where('code', 'fill_blank')->value('id')
            ?: DB::table('exercise_templates')->value('id');

        foreach (['recruit', 'shortlist', 'panel'] as $i => $word) {
            $itemId = DB::table('vocabulary_items')->insertGetId([
                'language_id' => $languageId, 'headword' => $word, 'normalised' => $word,
                'cefr_level_id' => $levelId, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $senseId = DB::table('vocabulary_senses')->insertGetId([
                'vocabulary_item_id' => $itemId, 'sense_number' => 1,
                'cefr_level_id' => $levelId, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $conceptId = DB::table('concepts')->insertGetId([
                'conceptable_type' => \App\Models\VocabularySense::class,
                'conceptable_id' => $senseId, 'language_id' => $languageId,
                'cefr_level_id' => $levelId, 'label' => $word, 'difficulty' => 0.0,
                'importance' => 0.5, 'is_active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('lesson_concept')->insert([
                'lesson_id' => $this->lessonId, 'concept_id' => $conceptId,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('lesson_blocks')->insert([
                'lesson_id' => $this->lessonId, 'type' => 'flashcard', 'position' => 200 + $i,
                'title' => $word,
                'config' => json_encode(['front' => $word, 'back' => 'a gloss', 'concept_id' => $conceptId]),
                'estimated_seconds' => 12, 'created_at' => now(), 'updated_at' => now(),
            ]);

            $exerciseId = DB::table('exercises')->insertGetId([
                'exercise_template_id' => $templateId,
                'language_id' => $languageId,
                'lesson_id' => $this->lessonId,
                'cefr_level_id' => $levelId,
                'stem' => "They will ______ ten people this year.",
                'instructions' => 'Complete the sentence.',
                'difficulty' => 0.0, 'discrimination' => 1.0, 'guessing' => 0.0,
                'status' => 'approved', 'generation_method' => 'derived_example',
                'copyright_status' => 'owned',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('exercise_concepts')->insert([
                'exercise_id' => $exerciseId, 'concept_id' => $conceptId,
                'weight' => 1.0, 'is_primary' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        LearnerProfile::create([
            'user_id' => $this->userId,
            'language_id' => $languageId,
            'current_cefr_level_id' => $levelId,
            'ability' => 0.0,
            'active_course_version_id' => $versionId,
        ]);
    }
}
