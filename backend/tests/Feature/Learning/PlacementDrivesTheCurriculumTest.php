<?php

namespace Tests\Feature\Learning;

use App\Models\LearnerProfile;
use App\Services\Learning\AdaptiveLearningService;
use App\Services\Learning\CoursePlacementService;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Placement used to be decoration. It wrote a CEFR level and an ability onto the
 * profile, and then the curriculum ignored both: `active_course_version_id` was
 * read in two places and assigned in none, so it stayed null and every learner -
 * A1 or C2 - was walked through the same lessons in database id order.
 *
 * These tests hold the connection between the two.
 */
class PlacementDrivesTheCurriculumTest extends TestCase
{
    use RefreshDatabase;

    private CoursePlacementService $courses;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        $this->courses = app(CoursePlacementService::class);
        // Built as the vocabulary series, because that is what the real import
        // produces and what the spine ladder is.
        $this->buildLadder('vocabulary');
    }

    public function test_a_beginner_and_an_advanced_learner_are_sent_to_different_books(): void
    {
        $beginner = $this->courses->versionForAbility(-2.0);
        $advanced = $this->courses->versionForAbility(2.6);

        $this->assertNotNull($beginner);
        $this->assertNotNull($advanced);
        $this->assertNotSame(
            $beginner,
            $advanced,
            'an A1 learner and a C2 learner were handed the same course',
        );
    }

    public function test_each_ability_lands_in_the_course_whose_range_contains_it(): void
    {
        $expected = [
            -2.0 => 'elementary',   // A1
            -1.0 => 'elementary',   // A2
            0.0 => 'middle',        // B1
            1.0 => 'upper',         // B2
            2.0 => 'advanced',      // C1
            2.9 => 'advanced',      // C2
        ];

        foreach ($expected as $ability => $slug) {
            $version = $this->courses->versionForAbility((float) $ability);
            $this->assertSame(
                $slug,
                $this->slugOf($version),
                "ability {$ability} should study the {$slug} book",
            );
        }
    }

    public function test_an_ability_beyond_the_hardest_book_still_gets_the_hardest_book(): void
    {
        $this->assertSame('advanced', $this->slugOf($this->courses->versionForAbility(9.0)));
    }

    public function test_an_unplaced_learner_starts_at_the_easiest_book(): void
    {
        $this->assertSame('elementary', $this->slugOf($this->courses->versionForAbility(null)));
    }

    public function test_completing_placement_assigns_the_course(): void
    {
        $profile = LearnerProfile::create([
            'user_id' => $this->makeUser(),
            'language_id' => DB::table('languages')->where('code', 'en')->value('id'),
            'ability' => 1.0,
        ]);

        $this->assertNull($profile->active_course_version_id);

        $this->courses->assign($profile);

        $this->assertSame('upper', $this->slugOf($profile->fresh()->active_course_version_id));
    }

    public function test_the_ladder_advances_one_book_at_a_time(): void
    {
        $ladder = $this->courses->ladder();

        $this->assertSame(
            ['elementary', 'middle', 'upper', 'advanced'],
            $ladder->pluck('slug')->all(),
            'the ladder must run easiest to hardest',
        );

        $first = (int) $ladder->first()->version_id;
        $second = $this->courses->nextVersionAfter($first);

        $this->assertSame('middle', $this->slugOf($second));
        $this->assertNull(
            $this->courses->nextVersionAfter((int) $ladder->last()->version_id),
            'there is nothing above the hardest book',
        );
    }

    public function test_the_session_builder_draws_its_lesson_from_the_assigned_book(): void
    {
        $userId = $this->makeUser();
        $profile = LearnerProfile::create([
            'user_id' => $userId,
            'language_id' => DB::table('languages')->where('code', 'en')->value('id'),
            'ability' => 1.0,
        ]);
        $this->courses->assign($profile);

        $session = app(AdaptiveLearningService::class)->buildNextSession($userId, 10);

        $lessonIds = DB::table('session_activities')
            ->where('learning_session_id', $session->id)
            ->where('activity_type', 'lesson_block')
            ->join('lesson_blocks', 'lesson_blocks.id', '=', 'session_activities.subject_id')
            ->pluck('lesson_blocks.lesson_id')
            ->unique();

        $this->assertNotEmpty($lessonIds, 'the session should contain curriculum work');

        $booksTouched = DB::table('lessons')
            ->join('units', 'units.id', '=', 'lessons.unit_id')
            ->join('modules', 'modules.id', '=', 'units.module_id')
            ->whereIn('lessons.id', $lessonIds)
            ->distinct()
            ->pluck('modules.course_version_id');

        $this->assertSame(
            [$profile->fresh()->active_course_version_id],
            $booksTouched->all(),
            'the session reached outside the book the learner was placed into',
        );
    }

    /**
     * The corpus grew from four books to sixteen, across six series that each
     * run their own elementary-to-advanced ladder. Ordering all of them
     * together by level would hand a learner placed at B1 whichever book
     * sorted first at B1, and "finishing" it would move them sideways into a
     * different subject and call that promotion.
     */
    public function test_a_second_series_does_not_get_into_the_spine_ladder(): void
    {
        $this->buildLadder('grammar', 'g-');

        $spine = $this->courses->ladder('vocabulary');

        $this->assertSame(
            ['elementary', 'middle', 'upper', 'advanced'],
            $spine->pluck('slug')->all(),
            'the spine ladder picked up books from another series',
        );

        $grammar = $this->courses->ladder('grammar');
        $this->assertSame(
            ['g-elementary', 'g-middle', 'g-upper', 'g-advanced'],
            $grammar->pluck('slug')->all(),
        );
    }

    public function test_finishing_a_book_moves_a_learner_up_not_sideways(): void
    {
        $this->buildLadder('grammar', 'g-');

        $firstGrammar = (int) $this->courses->ladder('grammar')->first()->version_id;

        $this->assertSame(
            'g-middle',
            $this->slugOf($this->courses->nextVersionAfter($firstGrammar)),
            'promotion left the series the learner was studying',
        );
    }

    public function test_every_series_offers_the_learner_a_book_at_their_level(): void
    {
        $this->buildLadder('grammar', 'g-');

        $strands = $this->courses->strandsForAbility(1.0);

        $this->assertSame(['vocabulary', 'grammar'], array_keys($strands));
        $this->assertSame('upper', $this->slugOf($strands['vocabulary']));
        $this->assertSame(
            'g-upper',
            $this->slugOf($strands['grammar']),
            'a B2 learner was offered the elementary book of the second series',
        );
    }

    /**
     * A session is built around one lesson of one book. With six series in the
     * corpus that leaves five of them unreachable: a learner placed on the
     * vocabulary spine would never meet a grammar question, which is precisely
     * what the platform was asked - where does it teach grammar?
     */
    public function test_a_session_reaches_the_other_series_at_the_learners_level(): void
    {
        $this->buildLadder('grammar', 'g-');
        $grammarVersion = $this->courses->ladder('grammar')
            ->firstWhere('slug', 'g-upper')->version_id;
        $this->makeServableExercise((int) $grammarVersion, 'grammar');

        $userId = $this->makeUser();
        $profile = LearnerProfile::create([
            'user_id' => $userId,
            'language_id' => DB::table('languages')->where('code', 'en')->value('id'),
            'ability' => 1.0,
        ]);
        $this->courses->assign($profile);

        $session = app(AdaptiveLearningService::class)->buildNextSession($userId, 20);

        $drivers = DB::table('session_activities')
            ->where('learning_session_id', $session->id)
            ->pluck('selection_reason')
            ->map(fn ($r) => json_decode((string) $r, true)['driver'] ?? null);

        $this->assertContains(
            'other_strand',
            $drivers->all(),
            'the session never left the book the learner was placed into',
        );
    }

    // ------------------------------------------------------------- fixtures

    /**
     * One approved, answerable item in the first lesson of a course version.
     */
    private function makeServableExercise(int $versionId, string $skillCode): int
    {
        $lessonId = DB::table('lessons')
            ->join('units', 'units.id', '=', 'lessons.unit_id')
            ->join('modules', 'modules.id', '=', 'units.module_id')
            ->where('modules.course_version_id', $versionId)
            ->orderBy('lessons.id')
            ->value('lessons.id');

        $exerciseId = DB::table('exercises')->insertGetId([
            'lesson_id' => $lessonId,
            'exercise_template_id' => DB::table('exercise_templates')->where('code', 'fill_blank')->value('id'),
            'language_id' => DB::table('languages')->where('code', 'en')->value('id'),
            'skill_id' => DB::table('skills')->where('code', $skillCode)->value('id'),
            'cefr_level_id' => DB::table('cefr_levels')->where('code', 'B2')->value('id'),
            'stem' => 'She ______ here since 2019.',
            'instructions' => 'Complete the sentence.',
            'difficulty' => 0.8,
            'status' => 'approved',
            'generation_method' => 'extracted',
            'copyright_status' => 'owned',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('exercise_answers')->insert([
            'exercise_id' => $exerciseId,
            'blank_index' => 0,
            'value' => 'has lived',
            'match_mode' => 'normalised',
            'is_primary' => true,
            'credit' => 1.000,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $exerciseId;
    }

    /**
     * Four courses spanning the ladder, each with one module, one unit and two
     * lessons, matching the shape the real import produces.
     */
    private function buildLadder(string $track = 'general', string $prefix = ''): void
    {
        $languageId = DB::table('languages')->where('code', 'en')->value('id');
        $levels = DB::table('cefr_levels')->pluck('id', 'code');

        $books = [
            ['elementary', 'A1', 'A2', -2.0],
            ['middle', 'A2', 'B1', -0.8],
            ['upper', 'B2', 'B2', 0.8],
            ['advanced', 'C1', 'C2', 2.0],
        ];

        foreach ($books as $position => [$slug, $from, $to, $difficulty]) {
            $slug = $prefix.$slug;
            if (DB::table('courses')->where('slug', $slug)->exists()) {
                continue;
            }
            $courseId = DB::table('courses')->insertGetId([
                'slug' => $slug,
                'language_id' => $languageId,
                'from_cefr_level_id' => $levels[$from],
                'to_cefr_level_id' => $levels[$to],
                'title' => ucfirst($slug),
                'track' => $track,
                'is_active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            $versionId = DB::table('course_versions')->insertGetId([
                'course_id' => $courseId,
                'version' => 1,
                'status' => 'published',
                'published_at' => now(),
                'created_at' => now(), 'updated_at' => now(),
            ]);

            $moduleId = DB::table('modules')->insertGetId([
                'course_version_id' => $versionId,
                'title' => "{$slug} module",
                'position' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            $unitId = DB::table('units')->insertGetId([
                'module_id' => $moduleId,
                'title' => "{$slug} unit",
                'cefr_level_id' => $levels[$to],
                'position' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            foreach ([1, 2] as $n) {
                $lessonId = DB::table('lessons')->insertGetId([
                    'unit_id' => $unitId,
                    'title' => "{$slug} lesson {$n}",
                    'cefr_level_id' => $levels[$to],
                    'kind' => 'vocabulary',
                    'position' => $n,
                    // The engine serves only what is published.
                    'status' => 'published',
                    'copyright_status' => 'owned',
                    'created_at' => now(), 'updated_at' => now(),
                ]);

                $conceptId = $this->makeConcept($languageId, $levels[$to], $difficulty, "{$slug}-{$n}");
                DB::table('lesson_concept')->insert([
                    'lesson_id' => $lessonId,
                    'concept_id' => $conceptId,
                    'created_at' => now(), 'updated_at' => now(),
                ]);

                DB::table('lesson_blocks')->insert([
                    'lesson_id' => $lessonId,
                    'type' => 'source_text',
                    'position' => 0,
                    'title' => 'Read',
                    'config' => json_encode(['text' => 'Body text.']),
                    'estimated_seconds' => 60,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            unset($position);
        }
    }

    private function makeConcept(int $languageId, int $levelId, float $difficulty, string $label): int
    {
        $senseId = DB::table('vocabulary_senses')->insertGetId([
            'vocabulary_item_id' => DB::table('vocabulary_items')->insertGetId([
                'language_id' => $languageId,
                'headword' => $label,
                'normalised' => $label,
                'cefr_level_id' => $levelId,
                'created_at' => now(), 'updated_at' => now(),
            ]),
            'sense_number' => 1,
            'cefr_level_id' => $levelId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('concepts')->insertGetId([
            'conceptable_type' => \App\Models\VocabularySense::class,
            'conceptable_id' => $senseId,
            'language_id' => $languageId,
            'cefr_level_id' => $levelId,
            'label' => $label,
            'difficulty' => $difficulty,
            'importance' => 0.5,
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeUser(): int
    {
        return DB::table('users')->insertGetId([
            'name' => 'Learner',
            'email' => 'learner'.uniqid().'@example.test',
            'password' => bcrypt('secret'),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function slugOf(?int $versionId): ?string
    {
        if ($versionId === null) {
            return null;
        }

        return DB::table('course_versions')
            ->join('courses', 'courses.id', '=', 'course_versions.course_id')
            ->where('course_versions.id', $versionId)
            ->value('courses.slug');
    }
}
