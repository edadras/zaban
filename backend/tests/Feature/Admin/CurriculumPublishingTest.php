<?php

namespace Tests\Feature\Admin;

use App\Models\CefrLevel;
use App\Models\Course;
use App\Models\CourseVersion;
use App\Models\Language;
use App\Models\Lesson;
use App\Models\Module;
use App\Models\SourceDocument;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Publishing is the one admin action that changes what a learner sees, so the
 * gate on it is the point of these tests.
 *
 * Everything imports as a draft, correctly: the pipeline reads scanned pages
 * and some of what it produces is a heading the scanner invented. What must not
 * happen is a bulk "publish everything" that sends a learner to a page holding
 * the printed text and nothing else - they arrive, read, and have nothing to do
 * and nowhere to go.
 */
class CurriculumPublishingTest extends TestCase
{
    use RefreshDatabase;

    private SourceDocument $document;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    /** A book with one lesson that can be published and one that cannot. */
    private function aBookOfTwoLessons(): array
    {
        $en = Language::where('code', 'en')->firstOrFail();
        $level = CefrLevel::where('code', 'A2')->firstOrFail();

        $this->document = SourceDocument::create([
            'title' => 'A book', 'language_id' => $en->id, 'cefr_level_id' => $level->id,
            'status' => 'processed', 'copyright_status' => 'owned',
        ]);

        $course = Course::create([
            'language_id' => $en->id, 'title' => 'Course', 'slug' => 'course',
            'from_cefr_level_id' => $level->id, 'to_cefr_level_id' => $level->id,
            'is_active' => true,
        ]);
        $version = CourseVersion::create([
            'course_id' => $course->id, 'version' => 1, 'status' => 'published', 'published_at' => now(),
        ]);
        $module = Module::create([
            'course_version_id' => $version->id, 'title' => 'Module', 'position' => 0,
        ]);
        $unit = Unit::create([
            'module_id' => $module->id, 'title' => 'Unit 1', 'position' => 1,
            'cefr_level_id' => $level->id, 'estimated_minutes' => 10,
        ]);

        $make = fn (string $title, int $position) => Lesson::create([
            'unit_id' => $unit->id, 'source_document_id' => $this->document->id,
            'title' => $title, 'position' => $position, 'cefr_level_id' => $level->id,
            'kind' => 'core', 'status' => 'draft', 'estimated_minutes' => 5,
            'generation_method' => 'extracted', 'copyright_status' => 'owned',
        ]);

        $ready = $make('Has something to do', 0);
        $bare = $make('Only the printed page', 1);

        $concept = DB::table('concepts')->insertGetId([
            'language_id' => $en->id, 'cefr_level_id' => $level->id,
            'conceptable_type' => Lesson::class, 'conceptable_id' => $ready->id,
            'label' => 'Present continuous', 'difficulty' => 0, 'importance' => 0.5,
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ([$ready, $bare] as $lesson) {
            DB::table('lesson_concept')->insert([
                'lesson_id' => $lesson->id, 'concept_id' => $concept,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('lesson_blocks')->insert([
                'lesson_id' => $lesson->id, 'type' => 'source_text', 'position' => 0,
                'config' => json_encode(['text' => 'the page']),
                'estimated_seconds' => 60, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        DB::table('lesson_blocks')->insert([
            'lesson_id' => $ready->id, 'type' => 'flashcard', 'position' => 1,
            'config' => json_encode(['front' => 'a', 'back' => 'b']),
            'estimated_seconds' => 20, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$ready, $bare];
    }

    public function test_a_book_reports_how_much_of_it_is_ready_and_how_much_is_out(): void
    {
        $this->aBookOfTwoLessons();

        $response = $this->actingAs($this->admin())
            ->getJson('/api/v1/admin/curriculum/books')
            ->assertOk();

        $book = collect($response->json('data'))
            ->firstWhere('id', $this->document->id);

        $this->assertSame(2, $book['lessons']);
        $this->assertSame(2, $book['teaching']);
        $this->assertSame(1, $book['ready']);
        $this->assertSame(0, $book['published']);
        $this->assertSame(0, $book['coverage']['artwork']);
    }

    public function test_a_lesson_with_nothing_to_do_cannot_be_published(): void
    {
        [, $bare] = $this->aBookOfTwoLessons();

        $this->actingAs($this->admin())
            ->patchJson("/api/v1/admin/curriculum/lessons/{$bare->id}", ['status' => 'published'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'lesson_not_ready');

        $this->assertSame('draft', $bare->fresh()->status);
    }

    public function test_a_lesson_that_carries_an_activity_can_be_published_and_withdrawn(): void
    {
        [$ready] = $this->aBookOfTwoLessons();
        $admin = $this->admin();

        $this->actingAs($admin)
            ->patchJson("/api/v1/admin/curriculum/lessons/{$ready->id}", ['status' => 'published'])
            ->assertOk();
        $this->assertSame('published', $ready->fresh()->status);

        $this->actingAs($admin)
            ->patchJson("/api/v1/admin/curriculum/lessons/{$ready->id}", ['status' => 'draft'])
            ->assertOk();
        $this->assertSame('draft', $ready->fresh()->status);
    }

    /**
     * The one that matters: publishing a whole book must leave the bare lesson
     * behind, and must say that it did.
     */
    public function test_publishing_a_book_holds_back_the_lessons_that_are_not_ready(): void
    {
        [$ready, $bare] = $this->aBookOfTwoLessons();

        $this->actingAs($this->admin())
            ->postJson("/api/v1/admin/curriculum/books/{$this->document->id}/publish")
            ->assertOk()
            ->assertJsonPath('data.published_now', 1)
            ->assertJsonPath('data.held_back', 1);

        $this->assertSame('published', $ready->fresh()->status);
        $this->assertSame('draft', $bare->fresh()->status);
    }

    public function test_withdrawing_a_book_takes_every_published_lesson_back(): void
    {
        [$ready] = $this->aBookOfTwoLessons();
        $admin = $this->admin();

        $this->actingAs($admin)
            ->postJson("/api/v1/admin/curriculum/books/{$this->document->id}/publish")
            ->assertOk();

        $this->actingAs($admin)
            ->postJson("/api/v1/admin/curriculum/books/{$this->document->id}/withdraw")
            ->assertOk()
            ->assertJsonPath('data.withdrawn', 1);

        $this->assertSame('draft', $ready->fresh()->status);
    }

    public function test_a_learner_cannot_publish_anything(): void
    {
        [$ready] = $this->aBookOfTwoLessons();

        $this->actingAs(User::factory()->create(['role' => 'learner']))
            ->patchJson("/api/v1/admin/curriculum/lessons/{$ready->id}", ['status' => 'published'])
            ->assertStatus(403);
    }

    public function test_the_lesson_list_says_what_each_one_is_missing(): void
    {
        $this->aBookOfTwoLessons();

        $rows = $this->actingAs($this->admin())
            ->getJson("/api/v1/admin/curriculum/books/{$this->document->id}/lessons")
            ->assertOk()
            // A paginator serialises as its own object, so the rows sit one
            // level in.
            ->json('data.data');

        $bare = collect($rows)->firstWhere('title', 'Only the printed page');
        $this->assertFalse($bare['has_activity']);
        $this->assertFalse($bare['publishable']);
        $this->assertFalse($bare['has_audio']);
        $this->assertTrue($bare['teaches']);
    }
}
