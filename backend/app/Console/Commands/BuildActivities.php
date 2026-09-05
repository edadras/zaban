<?php

namespace App\Console\Commands;

use App\Models\Concept;
use App\Models\Exercise;
use App\Models\ExerciseAnswer;
use App\Models\ExerciseOption;
use App\Models\ExerciseTemplate;
use App\Models\Lesson;
use App\Models\VocabularySense;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Turns imported source content into activities the engines can actually serve.
 *
 * Everything here is derived deterministically from what the books already give
 * us - the taught term, its example sentence, its gloss and its audio. Nothing is
 * invented. Richer generated material (scenes, dialogues, roleplay, generated
 * artwork) is the job of the AI layer and is deliberately not faked here.
 */
class BuildActivities extends Command
{
    protected $signature = 'content:build-activities {--fresh} {--limit=0}';

    protected $description = 'Derive interactive blocks and gradable exercises from imported content';

    private array $templates = [];

    /**
     * Bare function words that reach the term list when a bolded phrase is split
     * across text runs ("got married" yielding a stray "got"). They stay in the
     * knowledge graph - the books really do teach some of them as prepositions -
     * but a flashcard or distractor built from one teaches nothing, so they are
     * skipped when deriving activities.
     */
    private const ACTIVITY_STOPLIST = [
        'the', 'a', 'an', 'and', 'or', 'but', 'of', 'to', 'in', 'on', 'at', 'for',
        'with', 'is', 'are', 'was', 'were', 'be', 'been', 'being', 'have', 'has',
        'had', 'do', 'does', 'did', 'got', 'it', 'its', 'you', 'your', 'he', 'she',
        'they', 'we', 'i', 'this', 'that', 'these', 'those', 'not', 'no', 'so',
        'as', 'by', 'from', 'if', 'then', 'than', 'there', 'here',
    ];

    private function isActivityWorthy(string $term): bool
    {
        $t = Str::lower(trim($term));
        if ($t === '' || mb_strlen($t) < 2) {
            return false;
        }
        // Multi-word phrases are always worth teaching, even if they contain
        // function words ("got married", "in charge of").
        if (str_contains($t, ' ')) {
            return true;
        }

        return ! in_array($t, self::ACTIVITY_STOPLIST, true);
    }

    public function handle(): int
    {
        $this->templates = ExerciseTemplate::pluck('id', 'code')->all();

        if ($this->option('fresh')) {
            $this->warn('Clearing previously generated activities…');
            DB::statement('SET FOREIGN_KEY_CHECKS=0');
            $generated = Exercise::where('generation_method', 'derived')->pluck('id');
            DB::table('exercise_options')->whereIn('exercise_id', $generated)->delete();
            DB::table('exercise_answers')->whereIn('exercise_id', $generated)->delete();
            DB::table('exercise_concepts')->whereIn('exercise_id', $generated)->delete();
            DB::table('content_reviews')->where('reviewable_type', Exercise::class)
                ->whereIn('reviewable_id', $generated)->delete();
            Exercise::whereIn('id', $generated)->forceDelete();
            DB::table('lesson_blocks')->whereNotIn('type', ['source_text', 'image_scene'])->delete();
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }

        $this->linkSourceExercisesToConcepts();
        $this->spreadDifficulty();
        $this->buildFromVocabulary();
        $this->deriveWordFamilyPrerequisites();
        $this->markPlacementBank();

        $this->newLine();
        $this->info('Done. Re-run `php artisan content:readiness` to see the effect.');

        return self::SUCCESS;
    }

    /**
     * Source drills belong to a unit, and that unit's lessons teach a known set of
     * concepts. Linking them is what lets the adaptive engine reach for a real
     * exercise when a learner is weak on a concept.
     */
    private function linkSourceExercisesToConcepts(): void
    {
        $this->line('▸ linking source exercises to the concepts their unit teaches');
        $rows = DB::table('exercises')
            ->join('lessons', 'lessons.id', '=', 'exercises.lesson_id')
            ->select('exercises.id as exercise_id', 'lessons.unit_id')
            ->whereNull('exercises.deleted_at')
            ->get();

        $conceptsByUnit = DB::table('lesson_concept')
            ->join('lessons', 'lessons.id', '=', 'lesson_concept.lesson_id')
            ->select('lessons.unit_id', 'lesson_concept.concept_id')
            ->get()
            ->groupBy('unit_id');

        $now = now();
        $batch = [];
        foreach ($rows as $r) {
            foreach ($conceptsByUnit->get($r->unit_id, collect()) as $c) {
                $batch[] = [
                    'exercise_id' => $r->exercise_id,
                    'concept_id' => $c->concept_id,
                    'weight' => 1.000,
                    'is_primary' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            if (count($batch) >= 2000) {
                DB::table('exercise_concepts')->insertOrIgnore($batch);
                $batch = [];
            }
        }
        if ($batch) {
            DB::table('exercise_concepts')->insertOrIgnore($batch);
        }
        $this->line('   linked: '.DB::table('exercise_concepts')->count());
    }

    /**
     * A bank where every item sits at its CEFR midpoint cannot target a learner's
     * zone of proximal development. Spread difficulty using signals we actually
     * have: term length, how many words it spans, and how often the corpus reuses
     * it - rarer terms are harder.
     */
    private function spreadDifficulty(): void
    {
        $this->line('▸ spreading item difficulty within each CEFR band');

        $reuse = DB::table('concepts')
            ->select('label', DB::raw('COUNT(*) n'))
            ->groupBy('label')->pluck('n', 'label')->all();

        $concepts = DB::table('concepts')
            ->join('cefr_levels', 'cefr_levels.id', '=', 'concepts.cefr_level_id')
            ->select('concepts.id', 'concepts.label', 'cefr_levels.ability_min', 'cefr_levels.ability_max')
            ->get();

        foreach ($concepts->chunk(1000) as $chunk) {
            $cases = [];
            $ids = [];
            foreach ($chunk as $c) {
                $span = (float) $c->ability_max - (float) $c->ability_min;
                $words = max(1, str_word_count($c->label));
                $len = mb_strlen($c->label);
                // 0..1 within the band
                $lenScore = min(1.0, ($len - 3) / 22);
                $multiWord = min(1.0, ($words - 1) / 3);
                $rarity = 1.0 - min(1.0, (($reuse[$c->label] ?? 1) - 1) / 4);
                $pos = max(0.05, min(0.95, 0.45 * $lenScore + 0.25 * $multiWord + 0.30 * $rarity));
                $d = round((float) $c->ability_min + $span * $pos, 3);
                $ids[] = $c->id;
                $cases[] = "WHEN {$c->id} THEN {$d}";
            }
            DB::statement('UPDATE concepts SET difficulty = CASE id '.implode(' ', $cases).
                ' END WHERE id IN ('.implode(',', $ids).')');
        }

        // Source drills inherit the hardest concept they touch.
        DB::statement('
            UPDATE exercises e
            JOIN (
                SELECT ec.exercise_id, MAX(c.difficulty) d
                FROM exercise_concepts ec JOIN concepts c ON c.id = ec.concept_id
                GROUP BY ec.exercise_id
            ) x ON x.exercise_id = e.id
            SET e.difficulty = x.d
        ');
        $this->line('   distinct concept difficulties: '.DB::table('concepts')->distinct()->count('difficulty'));
    }

    /**
     * Build real, answerable items from each taught term: a cloze from its own
     * example sentence, a multiple-choice item with sibling distractors, and the
     * interactive blocks that make a lesson something a learner does.
     */
    private function buildFromVocabulary(): void
    {
        $this->line('▸ deriving activities from taught vocabulary');

        $limit = (int) $this->option('limit');
        $lessons = Lesson::with(['concepts' => fn ($q) => $q->with('conceptable')])
            ->whereHas('concepts')
            ->orderBy('id');
        if ($limit > 0) {
            $lessons->limit($limit);
        }

        $made = ['cloze' => 0, 'mcq' => 0, 'flashcard' => 0, 'listen' => 0, 'speak' => 0];

        $lessons->chunk(100, function ($chunk) use (&$made) {
            foreach ($chunk as $lesson) {
                $concepts = $lesson->concepts;
                if ($concepts->isEmpty()) {
                    continue;
                }

                // Sibling terms in the same lesson are the natural distractor pool:
                // same topic, same level, genuinely confusable.
                $pool = $concepts->pluck('label')->filter()
                    ->filter(fn ($l) => $this->isActivityWorthy($l))
                    ->unique()->values()->all();

                $audio = DB::table('audio_mappings')
                    ->join('audio_assets', 'audio_assets.id', '=', 'audio_mappings.audio_asset_id')
                    ->where('audio_mappings.mappable_type', Lesson::class)
                    ->where('audio_mappings.mappable_id', $lesson->id)
                    ->value('audio_assets.media_asset_id');

                $position = 1;
                foreach ($concepts as $concept) {
                    $sense = $concept->conceptable;
                    if (! $sense instanceof VocabularySense) {
                        continue;
                    }
                    $term = $concept->label;
                    if (! $this->isActivityWorthy($term)) {
                        continue;
                    }
                    $example = DB::table('examples')
                        ->where('exemplifiable_type', VocabularySense::class)
                        ->where('exemplifiable_id', $sense->id)
                        ->orderByRaw('CHAR_LENGTH(text) DESC')
                        ->value('text');
                    $gloss = DB::table('definitions')->where('vocabulary_sense_id', $sense->id)->value('text');

                    // --- cloze from the term's own example sentence ---
                    if ($example && $this->containsTerm($example, $term)) {
                        $stem = $this->blank($example, $term);
                        $ex = $this->makeExercise($lesson, $concept, 'fill_blank', $stem,
                            'Complete the sentence with the missing word.');
                        ExerciseAnswer::updateOrCreate(
                            ['exercise_id' => $ex->id, 'blank_index' => 0, 'value' => $term],
                            ['match_mode' => 'normalised', 'is_primary' => true, 'credit' => 1.000],
                        );
                        $made['cloze']++;

                        // --- multiple choice over the same cloze ---
                        $distractors = collect($pool)->reject(fn ($p) => Str::lower($p) === Str::lower($term))
                            ->shuffle()->take(3)->values();
                        if ($distractors->count() === 3) {
                            $mcq = $this->makeExercise($lesson, $concept, 'multiple_choice', $stem,
                                'Choose the word that completes the sentence.');
                            $opts = $distractors->push($term)->shuffle()->values();
                            foreach ($opts as $i => $opt) {
                                ExerciseOption::updateOrCreate(
                                    ['exercise_id' => $mcq->id, 'position' => $i],
                                    ['text' => $opt, 'is_correct' => Str::lower($opt) === Str::lower($term)],
                                );
                            }
                            ExerciseAnswer::updateOrCreate(
                                ['exercise_id' => $mcq->id, 'blank_index' => 0, 'value' => $term],
                                ['match_mode' => 'exact', 'is_primary' => true, 'credit' => 1.000],
                            );
                            $made['mcq']++;
                        }
                    }

                    // --- flashcard block: term on one side, gloss or example on the other ---
                    $back = $gloss ?: $example;
                    if ($back) {
                        $lesson->blocks()->updateOrCreate(
                            ['type' => 'flashcard', 'position' => 200 + $position],
                            [
                                'title' => $term,
                                'config' => ['front' => $term, 'back' => $back,
                                             'concept_id' => $concept->id,
                                             'audio_media_asset_id' => $audio],
                                'estimated_seconds' => 12,
                            ],
                        );
                        $made['flashcard']++;
                    }

                    $position++;
                }

                // --- audio-driven blocks, once per lesson, using the book's own recording ---
                if ($audio) {
                    $lesson->blocks()->updateOrCreate(
                        ['type' => 'listen_and_choose', 'position' => 300],
                        [
                            'title' => 'Listen',
                            'instructions' => 'Listen and choose what you hear.',
                            'config' => ['audio_media_asset_id' => $audio,
                                         'concept_ids' => $concepts->pluck('id')->take(6)->all()],
                            'estimated_seconds' => 45,
                        ],
                    );
                    $made['listen']++;

                    $lesson->blocks()->updateOrCreate(
                        ['type' => 'repeat_after_speaker', 'position' => 301],
                        [
                            'title' => 'Repeat',
                            'instructions' => 'Listen, then say it yourself.',
                            'config' => ['audio_media_asset_id' => $audio,
                                         'targets' => $concepts->pluck('label')
                                             ->filter(fn ($l) => $this->isActivityWorthy($l))
                                             ->take(8)->values()->all()],
                            'estimated_seconds' => 60,
                        ],
                    );
                    $made['speak']++;
                }
            }
        });

        foreach ($made as $k => $v) {
            $this->line("   {$k}: {$v}");
        }
    }

    /**
     * Real prerequisite edges we can prove: a multi-word term depends on the
     * single-word terms inside it, and a higher-CEFR sense of a headword depends
     * on the lower-CEFR sense of the same headword.
     */
    private function deriveWordFamilyPrerequisites(): void
    {
        $this->line('▸ deriving prerequisite edges');

        DB::statement("
            INSERT IGNORE INTO concept_prerequisites
                (concept_id, prerequisite_concept_id, strength, is_blocking, detection_method, created_at, updated_at)
            SELECT c2.id, c1.id, 0.700, 0, 'inferred', NOW(), NOW()
            FROM concepts c1
            JOIN concepts c2
              ON c2.id <> c1.id
             AND c2.language_id = c1.language_id
             AND CHAR_LENGTH(c2.label) > CHAR_LENGTH(c1.label)
             AND CHAR_LENGTH(c1.label) >= 4
             AND c2.label LIKE CONCAT('% ', c1.label, ' %')
            JOIN cefr_levels l1 ON l1.id = c1.cefr_level_id
            JOIN cefr_levels l2 ON l2.id = c2.cefr_level_id
            WHERE l1.ordinal <= l2.ordinal
        ");

        DB::statement("
            INSERT IGNORE INTO concept_prerequisites
                (concept_id, prerequisite_concept_id, strength, is_blocking, detection_method, created_at, updated_at)
            SELECT hi.id, lo.id, 0.900, 0, 'inferred', NOW(), NOW()
            FROM concepts hi
            JOIN vocabulary_senses shi ON shi.id = hi.conceptable_id
            JOIN vocabulary_senses slo ON slo.vocabulary_item_id = shi.vocabulary_item_id
            JOIN concepts lo ON lo.conceptable_id = slo.id AND lo.id <> hi.id
            JOIN cefr_levels lhi ON lhi.id = hi.cefr_level_id
            JOIN cefr_levels llo ON llo.id = lo.cefr_level_id
            WHERE hi.conceptable_type = 'App\\\\Models\\\\VocabularySense'
              AND lo.conceptable_type = 'App\\\\Models\\\\VocabularySense'
              AND llo.ordinal < lhi.ordinal
        ");

        $this->line('   prerequisite edges: '.DB::table('concept_prerequisites')->count());
    }

    /**
     * The placement bank needs auto-gradable, self-contained items spread across
     * the ability range. Multiple-choice items qualify; open prose does not.
     */
    private function markPlacementBank(): void
    {
        $this->line('▸ selecting the placement item bank');
        DB::table('exercises')->update(['is_placement_eligible' => false]);

        $bands = DB::table('cefr_levels')->orderBy('ordinal')->get();
        $total = 0;
        foreach ($bands as $band) {
            $ids = DB::table('exercises')
                ->join('exercise_options', 'exercise_options.exercise_id', '=', 'exercises.id')
                ->where('exercises.cefr_level_id', $band->id)
                ->where('exercises.generation_method', 'derived')
                ->whereNull('exercises.deleted_at')
                ->groupBy('exercises.id')
                ->havingRaw('COUNT(exercise_options.id) >= 4')
                ->orderByRaw('RAND(42)')
                ->limit(60)
                ->pluck('exercises.id');
            if ($ids->isEmpty()) {
                continue;
            }
            DB::table('exercises')->whereIn('id', $ids)->update(['is_placement_eligible' => true]);
            $total += $ids->count();
        }
        $this->line("   placement-eligible items: {$total}");
    }

    private function containsTerm(string $sentence, string $term): bool
    {
        return mb_stripos($sentence, $term) !== false;
    }

    private function blank(string $sentence, string $term): string
    {
        $pos = mb_stripos($sentence, $term);

        return mb_substr($sentence, 0, $pos).'______'.mb_substr($sentence, $pos + mb_strlen($term));
    }

    private function makeExercise(Lesson $lesson, Concept $concept, string $templateCode, string $stem, string $instructions): Exercise
    {
        $ex = Exercise::updateOrCreate(
            [
                'lesson_id' => $lesson->id,
                'exercise_template_id' => $this->templates[$templateCode],
                'source_reference' => "derived:{$concept->id}:{$templateCode}",
            ],
            [
                'language_id' => $concept->language_id,
                'skill_id' => $concept->skill_id,
                'cefr_level_id' => $concept->cefr_level_id,
                'stem' => Str::limit($stem, 900, ''),
                'instructions' => $instructions,
                'difficulty' => $concept->difficulty,
                'status' => 'draft',
                'generation_method' => 'derived',
                'copyright_status' => $lesson->copyright_status,
                'source_document_id' => $lesson->source_document_id,
                'source_page' => $lesson->source_page,
            ],
        );

        DB::table('exercise_concepts')->insertOrIgnore([
            'exercise_id' => $ex->id, 'concept_id' => $concept->id,
            'weight' => 1.000, 'is_primary' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $ex;
    }
}
