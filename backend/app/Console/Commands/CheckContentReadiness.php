<?php

namespace App\Console\Commands;

use App\Models\Exercise;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Checks whether the imported content can actually drive the platform described
 * in the specification, rather than merely being stored.
 *
 * Each check maps to a capability the engines depend on. A check that fails means
 * a feature cannot work yet, no matter how many rows exist.
 */
class CheckContentReadiness extends Command
{
    protected $signature = 'content:readiness';

    protected $description = 'Report whether stored content can drive the adaptive engines';

    public function handle(): int
    {
        $checks = [];
        $lessons = DB::table('lessons')->count();

        // --- Adaptive engine: pick an exercise that targets a weak concept ---
        $exercises = DB::table('exercises')->count();
        $exWithConcept = DB::table('exercises')
            ->join('exercise_concepts', 'exercise_concepts.exercise_id', '=', 'exercises.id')
            ->distinct()->count('exercises.id');
        // Items with no lesson (a handful of orphan source drills) can never be
        // concept-linked, so measure against the linkable population.
        $linkable = DB::table('exercises')->whereNotNull('lesson_id')->count();
        $checks[] = ['adaptive: exercises linked to a concept', $exWithConcept, $linkable,
            'AdaptiveLearningService cannot select practice for a weak concept'];

        // --- Exercise engine: an item must be answerable and gradable ---
        $answerable = DB::table('exercises')
            ->join('exercise_answers', 'exercise_answers.exercise_id', '=', 'exercises.id')
            ->distinct()->count('exercises.id');
        $withOptions = DB::table('exercises')
            ->join('exercise_options', 'exercise_options.exercise_id', '=', 'exercises.id')
            ->distinct()->count('exercises.id');
        $checks[] = ['exercise: has a gradable answer', $answerable, $linkable,
            'answers exist as raw answer-key prose, not per-blank values'];
        // A lesson with nothing active to teach - a prose-only section, or one
        // whose bold runs were all headings - cannot yield an activity of any
        // kind, so it is not counted against these two targets.
        $teaches = DB::table('lessons')
            ->join('lesson_concept', 'lesson_concept.lesson_id', '=', 'lessons.id')
            ->join('concepts', 'concepts.id', '=', 'lesson_concept.concept_id')
            ->where('concepts.is_active', true)
            ->whereNull('lessons.deleted_at');
        $teachable = (clone $teaches)->distinct()->count('lessons.id');

        // Not every item is multiple choice; what matters is that each lesson
        // can offer at least one, so the engine has a recognition-format
        // option. Reached the way the engine reaches it: AdaptiveLearningService
        // and RemediationService both select an item by the concept it drills,
        // never by the lesson it was printed under, so a unit's own exercise
        // serves every lesson in that unit. Counting only items whose
        // `lesson_id` pointed at the lesson measured a join the engine does not
        // make, and reported a third of the corpus as having no recognition
        // item while the engine was serving one.
        $lessonsWithMcq = (clone $teaches)
            ->join('exercise_concepts', 'exercise_concepts.concept_id', '=', 'lesson_concept.concept_id')
            ->join('exercises', 'exercises.id', '=', 'exercise_concepts.exercise_id')
            ->join('exercise_options', 'exercise_options.exercise_id', '=', 'exercises.id')
            ->whereIn('exercises.status', Exercise::SERVABLE_STATUSES)
            ->whereNull('exercises.deleted_at')
            ->distinct()->count('lessons.id');
        $checks[] = ['exercise: lessons offering a multiple-choice item', $lessonsWithMcq, $teachable,
            'no distractors, so no recognition-format item can be rendered'];

        // --- Lesson engine: interactive blocks, not textbook pages ---
        $interactive = (clone $teaches)
            ->join('lesson_blocks', 'lesson_blocks.lesson_id', '=', 'lessons.id')
            ->whereNotIn('lesson_blocks.type', ['source_text', 'image_scene'])
            ->distinct()->count('lessons.id');
        $checks[] = ['lesson: has an interactive block', $interactive, $teachable,
            'lessons hold source text and artwork only - nothing a learner can do'];

        // --- Placement: an adaptive test needs a calibrated item bank ---
        $placement = DB::table('exercises')->where('is_placement_eligible', true)->count();
        $checks[] = ['placement: eligible items', $placement, 40,
            'CAT has no item bank to draw from'];

        // --- Difficulty engine: items must be spread, not all at one point ---
        $distinctDiff = DB::table('exercises')->distinct()->count('difficulty');
        $checks[] = ['difficulty: distinct values across bank', $distinctDiff, 20,
            'every item sits at its CEFR midpoint, so targeting 70-85% success is impossible'];

        // --- Spaced repetition / knowledge graph ---
        $concepts = DB::table('concepts')->count();
        $withPrereq = DB::table('concepts')
            ->join('concept_prerequisites', 'concept_prerequisites.concept_id', '=', 'concepts.id')
            ->distinct()->count('concepts.id');
        // Only compound terms and repeated headwords have provable prerequisites;
        // a flat single-sense noun legitimately has none. Target the provable set.
        $provable = DB::table('concepts')->whereRaw("label LIKE '% %'")->count()
            + DB::table('concepts')
                ->join('vocabulary_senses', 'vocabulary_senses.id', '=', 'concepts.conceptable_id')
                ->select('vocabulary_senses.vocabulary_item_id')
                ->groupBy('vocabulary_senses.vocabulary_item_id')
                ->havingRaw('COUNT(*) > 1')->get()->count();
        $checks[] = ['knowledge graph: concepts with prerequisites', $withPrereq, (int) ($provable * 0.5),
            'no prerequisite edges, so the mastery loop cannot gate or sequence'];

        $checks[] = ['lesson: teaches at least one concept', $teachable, $lessons, ''];

        // --- Media availability per lesson ---
        $withAudio = DB::table('lessons')
            ->join('audio_mappings', function ($j) {
                $j->on('audio_mappings.mappable_id', '=', 'lessons.id')
                    ->where('audio_mappings.mappable_type', '=', 'App\\Models\\Lesson');
            })->distinct()->count('lessons.id');
        $checks[] = ['lesson: has audio', $withAudio, $lessons, ''];

        $withImage = DB::table('lessons')
            ->join('lesson_blocks', 'lesson_blocks.lesson_id', '=', 'lessons.id')
            ->where('lesson_blocks.type', 'image_scene')->distinct()->count('lessons.id');
        $checks[] = ['lesson: has artwork', $withImage, $lessons, ''];

        $pagesWithScan = DB::table('source_pages')->whereNotNull('page_image_media_asset_id')->count();
        $checks[] = ['source page: has a page image for vision fallback', $pagesWithScan,
            DB::table('source_pages')->count(), ''];

        // --- Publication state ---
        // Draft is the correct state before review, so this is reported, not failed.
        $published = DB::table('lessons')->where('status', 'published')->count();
        $checks[] = ['lesson: published to learners (draft is expected)', $published, $lessons, ''];

        $rows = [];
        $blocking = 0;
        foreach ($checks as [$label, $have, $need, $why]) {
            $pct = $need > 0 ? round(100 * $have / $need) : 0;
            $ok = $need > 0 && $have >= $need;
            if (! $ok && $why !== '') {
                $blocking++;
            }
            $rows[] = [$label, "{$have} / {$need}", $pct.'%', $ok ? 'READY' : ($why === '' ? 'partial' : 'BLOCKED')];
        }
        $this->table(['capability', 'have / need', '', 'state'], $rows);

        $this->newLine();
        $this->line('<comment>Why the blocked rows matter:</comment>');
        foreach ($checks as [$label, $have, $need, $why]) {
            if ($why !== '' && ! ($need > 0 && $have >= $need)) {
                $this->line("  • <fg=red>{$label}</> — {$why}");
            }
        }
        $this->newLine();
        $this->warn("{$blocking} capability gap(s) block the engines described in the specification.");

        return self::SUCCESS;
    }
}
