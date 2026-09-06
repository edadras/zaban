<?php

namespace Tests\Feature\Content;

use App\Models\Exercise;
use App\Models\Lesson;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Content the engine cannot reach is content nobody is taught.
 *
 * Every check here is about the join the runtime actually makes, not about
 * whether rows exist. The defect that prompted them was invisible in every
 * count: the grammar and pronunciation books imported completely - units,
 * lessons, forms, audio, cards - and then the build marked seven hundred and
 * nine of their eight hundred and thirty-eight concepts inactive, because a
 * grammar unit's concept is labelled the way the book labels it ("Present
 * continuous (I am doing)") and the test for "is this a headword or a section
 * heading?" reads that as a heading. AdaptiveLearningService selects concepts
 * `where is_active`, so two whole series were in the database and unreachable.
 *
 * They read the corpus through its own connection, and skip where it has not
 * been imported.
 */
class CurriculumReachabilityTest extends TestCase
{
    /** Sections whose heading and bold runs are both unreadable scan noise. */
    private const LESSONS_WITH_NOTHING_TO_DO = 24;

    /** Lessons in the five scanned books whose printed choice bank cannot be read. */
    private const LESSONS_WITH_NO_CHOICE_ITEM = 275;

    private function corpus(): ConnectionInterface
    {
        $connection = DB::connection('content');

        try {
            $connection->getPdo();
        } catch (\Throwable $e) {
            $this->markTestSkipped('The content database is not reachable here: '.$e->getMessage());
        }

        if ($connection->table('lessons')->count() === 0) {
            $this->markTestSkipped('The curriculum has not been imported here.');
        }

        return $connection;
    }

    /**
     * The point a grammar or pronunciation unit makes is the concept, and the
     * engine only ever selects an active one.
     */
    public function test_every_pattern_lesson_teaches_a_concept_the_engine_can_select(): void
    {
        $dark = $this->corpus()->table('concepts')
            ->where('conceptable_type', Lesson::class)
            ->where('is_active', false)
            ->pluck('label');

        $this->assertSame([], $dark->take(5)->all(),
            "{$dark->count()} grammar or pronunciation units are switched off");
    }

    /**
     * Lessons the engine teaches from must have something a learner can do.
     *
     * Two dozen do not, and they are the same two dozen each time: sections
     * whose heading came off a scan as "|" or "ele PETS SSeS pc Grrr Eu A",
     * and pronunciation units whose page is a table of sounds with no sentence
     * on it. Nothing can be built from them without inventing it, so what is
     * guarded here is that the number does not grow.
     */
    public function test_almost_every_teaching_lesson_has_an_interactive_block(): void
    {
        $bare = $this->teaching()
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))->from('lesson_blocks')
                    ->whereColumn('lesson_blocks.lesson_id', 'lessons.id')
                    ->whereNotIn('lesson_blocks.type', ['source_text', 'image_scene']);
            })
            ->distinct()->count('lessons.id');

        $this->assertLessThanOrEqual(self::LESSONS_WITH_NOTHING_TO_DO, $bare,
            "{$bare} lessons hold source text and artwork only");
    }

    /**
     * A recognition item, found the way the engine finds one: by the concept it
     * drills. A unit's own exercise serves every lesson in that unit, which is
     * why this is not a count of `exercises.lesson_id`.
     *
     * The two hundred and seventy-five that have none are `Basic Grammar in
     * Use`, `Advanced Grammar in Use` and the three pronunciation books. Each
     * of them prints a bank of choice questions - the grammars a Study guide,
     * the pronunciation books "circle the word you hear" - and each of them is
     * a scan whose key comes back as "152  8B" and whose alternatives come back
     * as "Ahaveto Bhadto Cmust". Re-reading those pages at 600 dpi recovers the
     * layout and still glues the words. An item built on a misread key marks a
     * right answer wrong, so they are left unasked rather than asked badly.
     */
    public function test_almost_every_teaching_lesson_can_be_asked_a_recognition_item(): void
    {
        // One of the lesson's concepts is enough; asking per concept would
        // count a lesson that can be asked about nine of its ten words.
        $able = $this->teaching()
            ->join('exercise_concepts', 'exercise_concepts.concept_id', '=', 'lesson_concept.concept_id')
            ->join('exercises', 'exercises.id', '=', 'exercise_concepts.exercise_id')
            ->join('exercise_options', 'exercise_options.exercise_id', '=', 'exercises.id')
            ->whereIn('exercises.status', Exercise::SERVABLE_STATUSES)
            ->whereNull('exercises.deleted_at')
            ->distinct()->pluck('lessons.id');

        $without = $this->teaching()->distinct()->pluck('lessons.id')->diff($able)->count();

        $this->assertLessThanOrEqual(self::LESSONS_WITH_NO_CHOICE_ITEM, $without,
            "{$without} lessons have no recognition-format item to offer");
    }

    /** Lessons with something active to teach. */
    private function teaching()
    {
        return $this->corpus()->table('lessons')
            ->join('lesson_concept', 'lesson_concept.lesson_id', '=', 'lessons.id')
            ->join('concepts', 'concepts.id', '=', 'lesson_concept.concept_id')
            ->where('concepts.is_active', true)
            ->whereNull('lessons.deleted_at');
    }
}
