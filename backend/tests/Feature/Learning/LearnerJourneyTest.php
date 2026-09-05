<?php

namespace Tests\Feature\Learning;

use App\Models\CefrLevel;
use App\Models\Concept;
use App\Models\Course;
use App\Models\CourseVersion;
use App\Models\Exercise;
use App\Models\ExerciseAnswer;
use App\Models\ExerciseOption;
use App\Models\ExerciseTemplate;
use App\Models\Language;
use App\Models\Lesson;
use App\Models\Module;
use App\Models\Skill;
use App\Models\Unit;
use App\Models\VocabularyItem;
use App\Models\VocabularySense;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Walks the whole learner journey the way a real user would: register, take the
 * adaptive placement test, receive a composed session, answer an item, and have
 * mastery and scheduling updated. If this passes, the engines are wired together
 * for real rather than merely existing.
 */
class LearnerJourneyTest extends TestCase
{
    use RefreshDatabase;

    private array $conceptIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        $this->buildCurriculum();
    }

    public function test_a_learner_can_register_place_and_study(): void
    {
        // --- register -------------------------------------------------------
        $register = $this->postJson('/api/v1/auth/register', [
            'name' => 'Sara',
            'email' => 'sara@example.test',
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
            'target_language' => 'en',
            'daily_target_minutes' => 10,
        ]);
        $register->assertCreated()
            ->assertJsonStructure(['data' => ['user' => ['id', 'email'], 'token']]);

        $token = $register->json('data.token');
        $headers = ['Authorization' => 'Bearer '.$token];

        $this->assertDatabaseHas('learner_profiles', [
            'user_id' => $register->json('data.user.id'),
            'placement_status' => 'not_started',
        ]);

        // --- placement ------------------------------------------------------
        $start = $this->postJson('/api/v1/placement/start', ['language' => 'en'], $headers);
        $start->assertCreated();
        $sessionId = $start->json('data.session_id');

        $answered = 0;
        while ($answered < 60) {
            $next = $this->getJson("/api/v1/placement/{$sessionId}/next", $headers);
            $next->assertOk();
            if ($next->json('data.complete')) {
                break;
            }
            $item = $next->json('data.item');
            $this->assertNotEmpty($item['id'], 'placement served an item without an id');

            // Answer correctly so the estimate has somewhere to move.
            $correct = Exercise::find($item['id'])->options()->where('is_correct', true)->value('text')
                ?? Exercise::find($item['id'])->answers()->value('value');

            $this->postJson("/api/v1/placement/{$sessionId}/submit", [
                'exercise_id' => $item['id'],
                'response' => $correct,
                'response_ms' => 4200,
            ], $headers)->assertOk();
            $answered++;
        }

        $this->assertGreaterThan(0, $answered, 'placement never served an item');

        $result = $this->getJson("/api/v1/placement/{$sessionId}/result", $headers);
        $result->assertOk()
            ->assertJsonStructure(['data' => ['overall' => ['cefr', 'ability', 'confidence'], 'skills']]);

        // Answering everything correctly must push the estimate above the start.
        $this->assertGreaterThan(0.0, (float) $result->json('data.overall.ability'),
            'ability did not rise after a run of correct answers');

        $this->assertDatabaseHas('learner_profiles', [
            'user_id' => $register->json('data.user.id'),
            'placement_status' => 'completed',
        ]);

        // --- composed session ----------------------------------------------
        $session = $this->getJson('/api/v1/session/next', $headers);
        $session->assertOk()->assertJsonStructure([
            'data' => ['id', 'composition', 'activities'],
            'meta' => ['due_reviews'],
        ]);
        $this->assertNotEmpty($session->json('data.activities'),
            'the engine composed an empty session');

        // Every activity must explain why it was chosen - that is what makes the
        // selection auditable rather than magic.
        foreach ($session->json('data.activities') as $activity) {
            $this->assertNotEmpty($activity['why']['driver'] ?? null,
                'an activity was scheduled with no recorded reason');
        }

        // --- answer an exercise ---------------------------------------------
        $exercise = Exercise::has('answers')->first();
        $expected = $exercise->answers()->value('value');

        $submit = $this->postJson("/api/v1/exercises/{$exercise->id}/submit", [
            'response' => $expected,
            'response_ms' => 3100,
        ], $headers);

        $submit->assertOk()->assertJsonPath('data.correct', true);
        $this->assertNotEmpty($submit->json('data.mastery'), 'no mastery was recorded for a graded answer');

        // A single correct answer must not read as mastered.
        $mastery = (float) $submit->json('data.mastery.0.mastery_score');
        $this->assertLessThanOrEqual(0.20, $mastery,
            'one correct answer should not exceed the "introduced" band');

        // A review must have been scheduled.
        $this->assertDatabaseHas('learner_concepts', [
            'user_id' => $register->json('data.user.id'),
            'concept_id' => $submit->json('data.mastery.0.concept_id'),
        ]);
        $this->assertNotNull($submit->json('data.mastery.0.next_review_at'),
            'answering an item did not schedule a review');

        // --- dashboard -------------------------------------------------------
        $this->getJson('/api/v1/progress/dashboard', $headers)
            ->assertOk()
            ->assertJsonStructure(['data' => ['cefr_level', 'skills', 'due_reviews', 'today']]);
    }

    public function test_grading_never_trusts_the_client(): void
    {
        $user = \App\Models\User::factory()->create();
        $exercise = Exercise::has('options')->first();
        $wrong = $exercise->options()->where('is_correct', false)->first();

        $response = $this->actingAs($user)->postJson("/api/v1/exercises/{$exercise->id}/submit", [
            'response' => $wrong->id,
            // A client claiming success must have no effect.
            'correct' => true,
            'score' => 1.0,
        ]);

        $response->assertOk()->assertJsonPath('data.correct', false);
        $this->assertDatabaseHas('exercise_attempts', [
            'user_id' => $user->id,
            'exercise_id' => $exercise->id,
            'is_correct' => 0,
        ]);
    }

    public function test_answer_keys_are_never_sent_to_the_client(): void
    {
        $user = \App\Models\User::factory()->create();
        $exercise = Exercise::has('options')->first();

        $response = $this->actingAs($user)->getJson("/api/v1/exercises/{$exercise->id}");
        $response->assertOk();

        foreach ($response->json('data.options') as $option) {
            $this->assertArrayNotHasKey('is_correct', $option,
                'the correct-answer flag leaked to the client');
        }
    }

    /** A small but real curriculum: units, lessons, concepts and gradable items. */
    private function buildCurriculum(): void
    {
        $en = Language::where('code', 'en')->firstOrFail();
        $a2 = CefrLevel::where('code', 'A2')->firstOrFail();
        $b1 = CefrLevel::where('code', 'B1')->firstOrFail();
        $vocab = Skill::where('code', 'vocabulary')->firstOrFail();
        $mcq = ExerciseTemplate::where('code', 'multiple_choice')->firstOrFail();
        $cloze = ExerciseTemplate::where('code', 'fill_blank')->firstOrFail();

        $course = Course::create([
            'language_id' => $en->id,
            'from_cefr_level_id' => $a2->id,
            'to_cefr_level_id' => $b1->id,
            'slug' => 'test-course',
            'title' => 'Test Course',
            'track' => 'general',
            'is_active' => true,
        ]);
        $version = CourseVersion::create([
            'course_id' => $course->id, 'version' => 1, 'status' => 'published', 'published_at' => now(),
        ]);
        $module = Module::create([
            'course_version_id' => $version->id, 'title' => 'Module 1', 'position' => 0,
        ]);
        $unit = Unit::create([
            'module_id' => $module->id, 'title' => 'Everyday words', 'position' => 1,
            'cefr_level_id' => $a2->id, 'estimated_minutes' => 10,
        ]);
        $lesson = Lesson::create([
            'unit_id' => $unit->id, 'title' => 'Around the house', 'position' => 0,
            'cefr_level_id' => $a2->id, 'kind' => 'core', 'status' => 'published',
            'estimated_minutes' => 5, 'generation_method' => 'authored', 'copyright_status' => 'owned',
        ]);

        $words = ['kettle', 'wardrobe', 'cushion', 'saucepan', 'blanket', 'curtain', 'drawer', 'mirror'];

        foreach ($words as $i => $word) {
            $item = VocabularyItem::create([
                'language_id' => $en->id, 'headword' => $word, 'normalised' => $word,
                'cefr_level_id' => $a2->id,
            ]);
            $sense = VocabularySense::create([
                'vocabulary_item_id' => $item->id, 'sense_number' => 1, 'cefr_level_id' => $a2->id,
            ]);
            $concept = Concept::create([
                'conceptable_type' => VocabularySense::class, 'conceptable_id' => $sense->id,
                'language_id' => $en->id, 'skill_id' => $vocab->id, 'cefr_level_id' => $a2->id,
                'label' => $word,
                // Spread across the band so item selection has something to choose between.
                'difficulty' => -1.4 + ($i * 0.35),
                'importance' => 0.5, 'is_active' => true,
            ]);
            $this->conceptIds[] = $concept->id;

            DB::table('lesson_concept')->insert([
                'lesson_id' => $lesson->id, 'concept_id' => $concept->id, 'role' => 'target',
                'created_at' => now(), 'updated_at' => now(),
            ]);

            // A multiple-choice item, eligible for placement.
            $mc = Exercise::create([
                'exercise_template_id' => $mcq->id, 'language_id' => $en->id, 'lesson_id' => $lesson->id,
                'skill_id' => $vocab->id, 'cefr_level_id' => $a2->id,
                'stem' => "Choose the word: a container for boiling water ({$word}?)",
                'instructions' => 'Choose the correct word.',
                'difficulty' => -1.4 + ($i * 0.35), 'discrimination' => 1.0, 'guessing' => 0.25,
                'status' => 'published', 'is_placement_eligible' => true,
                'generation_method' => 'authored', 'copyright_status' => 'owned',
            ]);
            $distractors = collect($words)->reject(fn ($w) => $w === $word)->shuffle()->take(3)->values();
            foreach ($distractors->push($word)->shuffle()->values() as $pos => $text) {
                ExerciseOption::create([
                    'exercise_id' => $mc->id, 'position' => $pos, 'text' => $text,
                    'is_correct' => $text === $word,
                ]);
            }
            DB::table('exercise_concepts')->insert([
                'exercise_id' => $mc->id, 'concept_id' => $concept->id, 'weight' => 1.0,
                'is_primary' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);

            // A cloze item with a gradable key.
            $fb = Exercise::create([
                'exercise_template_id' => $cloze->id, 'language_id' => $en->id, 'lesson_id' => $lesson->id,
                'skill_id' => $vocab->id, 'cefr_level_id' => $a2->id,
                'stem' => "I put the ______ on the shelf.",
                'instructions' => 'Complete the sentence.',
                'difficulty' => -1.2 + ($i * 0.3), 'discrimination' => 1.0,
                'status' => 'published', 'generation_method' => 'authored', 'copyright_status' => 'owned',
            ]);
            ExerciseAnswer::create([
                'exercise_id' => $fb->id, 'blank_index' => 0, 'value' => $word,
                'match_mode' => 'normalised', 'is_primary' => true, 'credit' => 1.0,
            ]);
            DB::table('exercise_concepts')->insert([
                'exercise_id' => $fb->id, 'concept_id' => $concept->id, 'weight' => 1.0,
                'is_primary' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }
}
