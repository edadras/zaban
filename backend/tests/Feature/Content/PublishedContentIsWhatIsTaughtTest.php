<?php

namespace Tests\Feature\Content;

use App\Models\CefrLevel;
use App\Models\Course;
use App\Models\CourseVersion;
use App\Models\Language;
use App\Models\Lesson;
use App\Models\Module;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Publishing has to decide something.
 *
 * The admin screen could publish and withdraw a book, and nothing downstream
 * read the column: the session engine picked lessons by course version and
 * concept, the browse endpoints listed every row, and a withdrawn lesson went
 * on being taught. The switch was a light with no wire behind it.
 *
 * Staff are the exception, and deliberately: an editor has to be able to look
 * at a draft before releasing it, which is the whole point of the draft.
 */
class PublishedContentIsWhatIsTaughtTest extends TestCase
{
    use RefreshDatabase;

    private Lesson $published;

    private Lesson $draft;

    private Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);

        $en = Language::where('code', 'en')->firstOrFail();
        $level = CefrLevel::where('code', 'A2')->firstOrFail();

        $course = Course::create([
            'language_id' => $en->id, 'title' => 'Course', 'slug' => 'course',
            'from_cefr_level_id' => $level->id, 'to_cefr_level_id' => $level->id,
            'is_active' => true,
        ]);
        $version = CourseVersion::create([
            'course_id' => $course->id, 'version' => 1, 'status' => 'published',
            'published_at' => now(),
        ]);
        $module = Module::create([
            'course_version_id' => $version->id, 'title' => 'Module', 'position' => 0,
        ]);
        $this->unit = Unit::create([
            'module_id' => $module->id, 'title' => 'Unit 1', 'position' => 1,
            'cefr_level_id' => $level->id, 'estimated_minutes' => 10,
        ]);

        $make = fn (string $title, string $status, int $position) => Lesson::create([
            'unit_id' => $this->unit->id, 'title' => $title, 'position' => $position,
            'cefr_level_id' => $level->id, 'kind' => 'vocabulary', 'status' => $status,
            'estimated_minutes' => 5, 'generation_method' => 'extracted',
            'copyright_status' => 'owned',
        ]);

        $this->published = $make('Out in the world', 'published', 0);
        $this->draft = $make('Not ready', 'draft', 1);
    }

    public function test_a_learner_browsing_a_unit_sees_only_what_is_published(): void
    {
        $titles = collect(
            $this->actingAs(User::factory()->create(['role' => 'learner']))
                ->getJson("/api/v1/units/{$this->unit->id}")
                ->assertOk()
                ->json('data.lessons'),
        )->pluck('title');

        $this->assertContains('Out in the world', $titles);
        $this->assertNotContains('Not ready', $titles);
    }

    public function test_an_editor_browsing_the_same_unit_sees_the_draft_too(): void
    {
        $titles = collect(
            $this->actingAs(User::factory()->create(['role' => 'editor']))
                ->getJson("/api/v1/units/{$this->unit->id}")
                ->assertOk()
                ->json('data.lessons'),
        )->pluck('title');

        $this->assertContains('Not ready', $titles);
    }

    public function test_opening_a_draft_lesson_is_a_404_for_a_learner_and_fine_for_staff(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'learner']))
            ->getJson("/api/v1/lessons/{$this->draft->id}")
            ->assertNotFound();

        $this->actingAs(User::factory()->create(['role' => 'reviewer']))
            ->getJson("/api/v1/lessons/{$this->draft->id}")
            ->assertOk();
    }

    /**
     * The one that matters: a session is what a learner is handed, so it must
     * never be built around a lesson nobody has released.
     */
    public function test_a_session_is_never_built_around_a_draft(): void
    {
        $concept = DB::table('concepts')->insertGetId([
            'language_id' => Language::where('code', 'en')->value('id'),
            'cefr_level_id' => CefrLevel::where('code', 'A2')->value('id'),
            'conceptable_type' => Lesson::class, 'conceptable_id' => $this->draft->id,
            'label' => 'Something', 'difficulty' => 0, 'importance' => 0.5,
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('lesson_concept')->insert([
            'lesson_id' => $this->draft->id, 'concept_id' => $concept,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $chosen = DB::table('lessons')
            ->join('units', 'units.id', '=', 'lessons.unit_id')
            ->join('modules', 'modules.id', '=', 'units.module_id')
            ->join('lesson_concept', 'lesson_concept.lesson_id', '=', 'lessons.id')
            ->join('concepts', 'concepts.id', '=', 'lesson_concept.concept_id')
            ->where('concepts.is_active', true)
            ->where('lessons.status', 'published')
            ->pluck('lessons.id');

        $this->assertNotContains($this->draft->id, $chosen);
    }

    public function test_publishing_is_repeatable_from_the_command_line(): void
    {
        // The block is what makes a lesson publishable at all.
        DB::table('lesson_blocks')->insert([
            'lesson_id' => $this->draft->id, 'type' => 'flashcard', 'position' => 0,
            'config' => json_encode(['front' => 'a', 'back' => 'b']),
            'estimated_seconds' => 20, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $concept = DB::table('concepts')->insertGetId([
            'language_id' => Language::where('code', 'en')->value('id'),
            'cefr_level_id' => CefrLevel::where('code', 'A2')->value('id'),
            'conceptable_type' => Lesson::class, 'conceptable_id' => $this->draft->id,
            'label' => 'Something', 'difficulty' => 0, 'importance' => 0.5,
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('lesson_concept')->insert([
            'lesson_id' => $this->draft->id, 'concept_id' => $concept,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->artisan('content:publish')->assertSuccessful();
        $this->assertSame('published', $this->draft->fresh()->status);

        $this->artisan('content:publish --withdraw')->assertSuccessful();
        $this->assertSame('draft', $this->draft->fresh()->status);
        $this->assertSame('draft', $this->published->fresh()->status);
    }
}
