<?php

namespace App\Console\Commands;

use App\Models\Concept;
use App\Models\Exercise;
use App\Models\ExerciseAnswer;
use App\Models\ExerciseOption;
use App\Models\ExerciseTemplate;
use App\Models\Lesson;
use App\Models\VocabularySense;
use App\Services\Content\DistractorPolicy;
use App\Services\Content\SentenceQuality;
use App\Services\Content\SourceSentenceMiner;
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
     * The fewest cards a lesson must yield before a session can be built
     * around it: below this there is nothing to study and nothing to practise
     * afterwards.
     */
    private const WORDS_FOR_A_VOCABULARY_LESSON = 3;

    /** Distractor candidates by CEFR level id, loaded once. */
    private array $pool = [];

    public function __construct(
        private SentenceQuality $quality,
        private DistractorPolicy $distractors,
        private SourceSentenceMiner $miner,
    ) {
        parent::__construct();
    }

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

        // A headword is a word or a short phrase. The section headings of the
        // "how to study" units at the front of each book were arriving as
        // vocabulary - "What does knowing a new word mean?" was a flashcard.
        if (! $this->distractors->isUsableTerm($term)) {
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
            $generated = Exercise::where('generation_method', 'like', 'derived%')->pluck('id');
            DB::table('exercise_options')->whereIn('exercise_id', $generated)->delete();
            DB::table('exercise_answers')->whereIn('exercise_id', $generated)->delete();
            DB::table('exercise_concepts')->whereIn('exercise_id', $generated)->delete();
            DB::table('content_reviews')->where('reviewable_type', Exercise::class)
                ->whereIn('reviewable_id', $generated)->delete();
            Exercise::whereIn('id', $generated)->forceDelete();
            DB::table('lesson_blocks')->whereNotIn('type', ['source_text', 'image_scene'])->delete();
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }

        $this->deactivateNonTerms();
        $this->linkSourceExercisesToConcepts();
        $this->spreadDifficulty();
        $this->buildFromVocabulary();
        $this->classifyLessons();
        $this->deriveWordFamilyPrerequisites();
        $this->markPlacementBank();

        $this->newLine();
        $this->info('Done. Re-run `php artisan content:readiness` to see the effect.');

        return self::SUCCESS;
    }

    /**
     * Stop treating section headings as vocabulary.
     *
     * Each book opens with units about how to study - "Using this book",
     * "Learning vocabulary" - and their headings were extracted as headwords,
     * so "What does knowing a new word mean?" became a concept with a flashcard
     * and a place in the knowledge graph, and the front-matter unit that held it
     * was the first lesson served to anyone starting the book.
     *
     * The rows are kept. They came out of the book and the body text they title
     * is still taught; they are simply no longer taught as words.
     */
    private function deactivateNonTerms(): void
    {
        $this->line('▸ separating headwords from headings');

        $labels = DB::table('concepts')->pluck('label', 'id');

        $headings = $labels
            ->reject(fn ($label) => $this->distractors->isUsableTerm((string) $label))
            ->keys();

        DB::table('concepts')->update(['is_active' => true]);

        if ($headings->isNotEmpty()) {
            $headings->chunk(1000)->each(
                fn ($ids) => DB::table('concepts')->whereIn('id', $ids->all())->update(['is_active' => false]),
            );
        }

        $this->line('   headings set aside: '.$headings->count().' of '.$labels->count());
    }

    /**
     * Say what each lesson is, so the daily session can pick the right one.
     *
     * Every lesson was imported as `core`, which told nothing apart. Each book
     * opens with a section on how to study - "What do you need to learn?", "How
     * can you help yourself to memorise words?" - real pages of the book, but
     * pages about learning rather than pages that teach words. They sort first,
     * so they were the first thing every learner was shown, and since they teach
     * no vocabulary the session had nothing to practise afterwards.
     *
     * A lesson that can put words in front of a learner is `vocabulary`; one
     * that cannot is `study_skills`. Both stay in the course and both remain
     * browsable. Only the first kind drives a daily session.
     */
    private function classifyLessons(): void
    {
        $this->line('▸ classifying lessons');

        // Counted from what the lesson produced, not from what it nominally
        // holds: a page can list eleven terms and still yield no card, because
        // none of them came with a gloss or a sentence worth showing. Judging it
        // on the concept count sent learners to lessons that had nothing to
        // study and nothing to practise afterwards.
        $teaching = DB::table('lesson_blocks')
            ->where('type', 'flashcard')
            ->groupBy('lesson_id')
            ->havingRaw('COUNT(*) >= ?', [self::WORDS_FOR_A_VOCABULARY_LESSON])
            ->pluck('lesson_id');

        DB::table('lessons')->update(['kind' => 'study_skills']);
        $teaching->chunk(1000)->each(
            fn ($ids) => DB::table('lessons')->whereIn('id', $ids->all())->update(['kind' => 'vocabulary']),
        );

        $total = DB::table('lessons')->count();
        $this->line("   vocabulary lessons: {$teaching->count()} of {$total}");
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
     * Place every concept on the ability scale, and label it from where it lands.
     *
     * Two things were wrong before. Difficulty was spread inside a single CEFR
     * band, and the band came from the book's nominal level - so the Elementary
     * book, which teaches A1 *and* A2, produced nothing but A2, and the Advanced
     * book, which teaches C1 and C2, produced nothing but C2. The placement bank
     * inherited both holes: no item existed below -1.5 or between 1.5 and 2.5,
     * and a learner sitting in either gap could not be measured.
     *
     * So each concept is now spread across its course's declared range, and its
     * CEFR label is read back off the scale rather than copied from the cover of
     * the book. The signals are the ones the corpus actually gives us: how long
     * the term is, how many words it spans, and how often the corpus reuses it -
     * a term that appears once is rarer, and rarer is harder.
     */
    private function spreadDifficulty(): void
    {
        $this->line('▸ placing concepts on the ability scale');

        $reuse = DB::table('concepts')
            ->select('label', DB::raw('COUNT(*) n'))
            ->groupBy('label')->pluck('n', 'label')->all();

        $levels = DB::table('cefr_levels')->orderBy('ordinal')
            ->get(['id', 'code', 'ability_min', 'ability_max']);

        $concepts = DB::table('concepts')
            ->join('lesson_concept', 'lesson_concept.concept_id', '=', 'concepts.id')
            ->join('lessons', 'lessons.id', '=', 'lesson_concept.lesson_id')
            ->join('units', 'units.id', '=', 'lessons.unit_id')
            ->join('modules', 'modules.id', '=', 'units.module_id')
            ->join('course_versions', 'course_versions.id', '=', 'modules.course_version_id')
            ->join('courses', 'courses.id', '=', 'course_versions.course_id')
            ->join('cefr_levels as lo', 'lo.id', '=', 'courses.from_cefr_level_id')
            ->join('cefr_levels as hi', 'hi.id', '=', 'courses.to_cefr_level_id')
            ->groupBy('concepts.id', 'concepts.label')
            ->select([
                'concepts.id',
                'concepts.label',
                DB::raw('MIN(lo.ability_min) as band_min'),
                DB::raw('MAX(hi.ability_max) as band_max'),
            ])
            ->get();

        // Rank inside the course, not raw score.
        //
        // The raw signal is a weighted sum, and weighted sums pile up in the
        // middle: scored directly, three quarters of the bank landed in three
        // bins and the B1/B2 boundary held a single item. Ranking spreads the
        // same ordering evenly over the course's range, which is what lets the
        // adaptive test find an informative question wherever a learner sits.
        $byCourse = $concepts->groupBy(fn ($c) => $c->band_min.':'.$c->band_max);

        $placed = [];
        foreach ($byCourse as $group) {
            $ordered = $group
                ->map(function ($c) use ($reuse) {
                    $c->score = $this->positionInBand($c->label, $reuse[$c->label] ?? 1);

                    return $c;
                })
                ->sortBy([['score', 'asc'], ['label', 'asc']])
                ->values();

            $last = max(1, $ordered->count() - 1);

            foreach ($ordered as $rank => $c) {
                $min = (float) $c->band_min;
                $max = (float) $c->band_max;
                $placed[] = [
                    'id' => $c->id,
                    'difficulty' => round($min + ($max - $min) * ($rank / $last), 3),
                ];
            }
        }

        foreach (array_chunk($placed, 500) as $chunk) {
            $difficulty = [];
            $level = [];
            $ids = [];

            foreach ($chunk as $c) {
                $ids[] = $c['id'];
                $difficulty[] = "WHEN {$c['id']} THEN {$c['difficulty']}";
                $level[] = "WHEN {$c['id']} THEN ".$this->levelIdFor($levels, $c['difficulty']);
            }

            $in = implode(',', $ids);
            DB::statement(
                'UPDATE concepts SET difficulty = CASE id '.implode(' ', $difficulty).' END,'
                .' cefr_level_id = CASE id '.implode(' ', $level).' END'
                ." WHERE id IN ({$in})",
            );
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

        $spread = DB::table('concepts')
            ->join('cefr_levels', 'cefr_levels.id', '=', 'concepts.cefr_level_id')
            ->select('cefr_levels.code', DB::raw('COUNT(*) n'))
            ->groupBy('cefr_levels.code')
            ->orderByRaw('MIN(cefr_levels.ordinal)')
            ->pluck('n', 'code');

        $this->line('   concepts by level: '.$spread->map(fn ($n, $c) => "{$c} {$n}")->implode('  '));
    }

    /** Where in its course's range this term sits, 0 (easiest) to 1 (hardest). */
    private function positionInBand(string $label, int $reuse): float
    {
        $words = max(1, str_word_count($label));
        $length = mb_strlen($label);

        $lengthScore = min(1.0, max(0.0, ($length - 3) / 22));
        $multiWord = min(1.0, ($words - 1) / 3);
        $rarity = 1.0 - min(1.0, ($reuse - 1) / 4);

        return max(0.02, min(0.98, 0.45 * $lengthScore + 0.25 * $multiWord + 0.30 * $rarity));
    }

    private function levelIdFor($levels, float $ability): int
    {
        foreach ($levels as $level) {
            if ($ability >= (float) $level->ability_min && $ability < (float) $level->ability_max) {
                return (int) $level->id;
            }
        }

        return (int) $levels->last()->id;
    }

    /**
     * Build items a fluent speaker could actually get right.
     *
     * Two gates stand between the books and the bank. SentenceQuality decides
     * whether an extracted example is a sentence at all - most of the ruined
     * items came from fragments and glued lines that no gate was checking.
     * DistractorPolicy decides whether the wrong answers are wrong, and refuses
     * to build a choice item when it cannot show that they are.
     *
     * An item whose distractors are only plausible stays `draft`: good practice,
     * but not something to measure a learner's level with.
     */
    private function buildFromVocabulary(): void
    {
        $this->line('▸ deriving activities from taught vocabulary');

        $this->pool = $this->loadDistractorPool();
        $pages = $this->loadPageText();
        $glosses = $this->loadGlosses();

        $limit = (int) $this->option('limit');
        $lessons = Lesson::with([
            'concepts' => fn ($q) => $q->where('concepts.is_active', true)->with('conceptable'),
            'unit',
        ])
            ->whereHas('concepts', fn ($q) => $q->where('concepts.is_active', true))
            ->orderBy('id');
        if ($limit > 0) {
            $lessons->limit($limit);
        }

        $made = ['cloze' => 0, 'mcq_proven' => 0, 'mcq_plausible' => 0, 'flashcard' => 0, 'listen' => 0, 'speak' => 0];
        $skipped = ['no_usable_example' => 0, 'no_safe_distractors' => 0];

        $lessons->chunk(100, function ($chunk) use (&$made, &$skipped, $pages, $glosses) {
            foreach ($chunk as $lesson) {
                $concepts = $lesson->concepts;
                if ($concepts->isEmpty()) {
                    continue;
                }

                $moduleId = $lesson->unit?->module_id;
                $siblings = $this->siblingCandidates($concepts, $moduleId);
                $pageText = $pages[$lesson->id] ?? null;
                $pageGlosses = $glosses[$lesson->id] ?? [];
                $mined = null;

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

                    $definition = DB::table('definitions')->where('vocabulary_sense_id', $sense->id)->value('text');

                    // A sentence the importer isolated as an example is the most
                    // trustworthy stem there is, so it is tried first. When the
                    // term has none - and two thirds of them do not - the page
                    // that teaches the word is read for a sentence that uses it.
                    $example = $this->bestExample($sense->id, $term);
                    $provenance = 'derived_example';

                    if ($example === null && $pageText !== null) {
                        $mined ??= $this->miner->sentences($pageText, $pageGlosses);
                        foreach ($mined as $candidate) {
                            if ($this->quality->containsTerm($candidate, $term)) {
                                $example = $candidate;
                                $provenance = 'derived_mined';
                                break;
                            }
                        }
                    }

                    if ($example === null) {
                        $skipped['no_usable_example']++;
                    } else {
                        $stem = $this->quality->blank($example, $term);

                        if ($stem !== null) {
                            $ex = $this->makeExercise($lesson, $concept, 'fill_blank', $stem,
                                'Complete the sentence with the missing word.', 'approved', $provenance);
                            $ex->update(['validation_score' => 1.000]);
                            ExerciseAnswer::updateOrCreate(
                                ['exercise_id' => $ex->id, 'blank_index' => 0, 'value' => $term],
                                ['match_mode' => 'normalised', 'is_primary' => true, 'credit' => 1.000],
                            );
                            $made['cloze']++;

                            $chosen = $this->distractors->choose(
                                $term,
                                $definition,
                                $stem,
                                $this->candidatesFor($concept, $siblings),
                                3,
                                $moduleId,
                            );

                            if ($chosen === null) {
                                $skipped['no_safe_distractors']++;
                            } else {
                                $proven = $chosen['grade'] === DistractorPolicy::PROVEN;

                                // Both grades are servable - a plausible item is
                                // good practice. The grade decides what may
                                // measure a learner, not what may be shown to
                                // one, so it is recorded rather than hidden
                                // behind a draft status that also means
                                // "not answerable at all".
                                $mcq = $this->makeExercise($lesson, $concept, 'multiple_choice', $stem,
                                    'Choose the word that completes the sentence.',
                                    'approved', $provenance);
                                $mcq->update(['validation_score' => $proven ? 1.000 : 0.600]);

                                // Deterministic shuffle: the same build produces the
                                // same paper twice, which is what makes a regression
                                // in item quality visible in a diff.
                                $opts = collect($chosen['options'])->push($term)->values()->all();
                                usort($opts, fn ($a, $b) => crc32($concept->id.$a) <=> crc32($concept->id.$b));

                                ExerciseOption::where('exercise_id', $mcq->id)->delete();
                                foreach ($opts as $i => $opt) {
                                    ExerciseOption::create([
                                        'exercise_id' => $mcq->id,
                                        'position' => $i,
                                        'text' => $opt,
                                        'is_correct' => Str::lower($opt) === Str::lower($term),
                                    ]);
                                }
                                ExerciseAnswer::updateOrCreate(
                                    ['exercise_id' => $mcq->id, 'blank_index' => 0, 'value' => $term],
                                    ['match_mode' => 'exact', 'is_primary' => true, 'credit' => 1.000],
                                );
                                $made[$proven ? 'mcq_proven' : 'mcq_plausible']++;
                            }
                        }
                    }

                    // --- flashcard block: term on one side, gloss or example on the other ---
                    $back = $definition ?: $example;
                    if ($back) {
                        $lesson->blocks()->updateOrCreate(
                            ['type' => 'flashcard', 'position' => 200 + $position],
                            [
                                'title' => $term,
                                'config' => ['front' => $term, 'back' => $back,
                                             'example' => $example,
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
        $this->line('   skipped, no usable example sentence: '.$skipped['no_usable_example']);
        $this->line('   cloze kept but no provable choice item: '.$skipped['no_safe_distractors']);
    }

    /**
     * The shortest example that is a real sentence and uses the term.
     *
     * The old rule took the longest string on file, which is exactly the one
     * most likely to be two lines glued together. Short and clean beats long.
     */
    private function bestExample(int $senseId, string $term): ?string
    {
        $texts = DB::table('examples')
            ->where('exemplifiable_type', VocabularySense::class)
            ->where('exemplifiable_id', $senseId)
            ->orderByRaw('CHAR_LENGTH(text) ASC')
            ->pluck('text');

        foreach ($texts as $text) {
            if ($this->quality->isUsableSentence($text) && $this->quality->containsTerm($text, $term)) {
                return trim($text);
            }
        }

        return null;
    }

    /**
     * The printed page behind each lesson, in reading order.
     *
     * `source_pages.text` is pdftotext -layout output, which is the only copy of
     * the page that keeps its columns apart. The lesson blocks hold the same
     * page as pdftohtml runs, and those are interleaved.
     *
     * @return array<int, string>
     */
    private function loadPageText(): array
    {
        return DB::table('lessons')
            ->join('source_files', 'source_files.source_document_id', '=', 'lessons.source_document_id')
            ->join('source_pages', function ($j) {
                $j->on('source_pages.source_file_id', '=', 'source_files.id')
                    ->on('source_pages.page_number', '=', 'lessons.source_page');
            })
            ->whereNull('lessons.deleted_at')
            ->pluck('source_pages.text', 'lessons.id')
            ->all();
    }

    /**
     * The margin notes printed on each lesson's page.
     *
     * A mined sentence that contains one of these swallowed the margin column,
     * so the glosses are how a column splice is recognised.
     *
     * @return array<int, array<int, string>>
     */
    private function loadGlosses(): array
    {
        return DB::table('definitions')
            ->join('concepts', function ($j) {
                $j->on('concepts.conceptable_id', '=', 'definitions.vocabulary_sense_id')
                    ->where('concepts.conceptable_type', '=', VocabularySense::class);
            })
            ->join('lesson_concept', 'lesson_concept.concept_id', '=', 'concepts.id')
            ->select('lesson_concept.lesson_id', 'definitions.text')
            ->get()
            ->groupBy('lesson_id')
            ->map(fn ($g) => $g->pluck('text')->all())
            ->all();
    }

    /**
     * Every taught term with its gloss and the module that teaches it, grouped by
     * level so a distractor is always drawn from the learner's own band.
     *
     * @return array<int, array<int, array{term: string, definition: ?string, module_id: ?int, concept_id: int}>>
     */
    private function loadDistractorPool(): array
    {
        $rows = DB::table('concepts')
            ->join('lesson_concept', 'lesson_concept.concept_id', '=', 'concepts.id')
            ->join('lessons', 'lessons.id', '=', 'lesson_concept.lesson_id')
            ->join('units', 'units.id', '=', 'lessons.unit_id')
            ->leftJoin('definitions', function ($j) {
                $j->on('definitions.vocabulary_sense_id', '=', 'concepts.conceptable_id');
            })
            ->where('concepts.conceptable_type', VocabularySense::class)
            ->select([
                'concepts.id as concept_id',
                'concepts.label as term',
                'concepts.cefr_level_id',
                'units.module_id',
                'definitions.text as definition',
            ])
            ->get();

        $pool = [];
        $seen = [];
        foreach ($rows as $r) {
            if (isset($seen[$r->concept_id]) || ! $this->isActivityWorthy($r->term)) {
                continue;
            }
            $seen[$r->concept_id] = true;
            $pool[(int) $r->cefr_level_id][] = [
                'term' => $r->term,
                'definition' => $r->definition,
                'module_id' => $r->module_id === null ? null : (int) $r->module_id,
                'concept_id' => (int) $r->concept_id,
            ];
        }

        return $pool;
    }

    /**
     * @return array<int, array{term: string, definition: ?string, module_id: ?int, concept_id: int}>
     */
    private function siblingCandidates($concepts, ?int $moduleId): array
    {
        $ids = $concepts->pluck('conceptable_id')->all();
        $defs = DB::table('definitions')->whereIn('vocabulary_sense_id', $ids)
            ->pluck('text', 'vocabulary_sense_id')->all();

        return $concepts
            ->filter(fn ($c) => $this->isActivityWorthy($c->label))
            ->map(fn ($c) => [
                'term' => $c->label,
                'definition' => $defs[$c->conceptable_id] ?? null,
                'module_id' => $moduleId,
                'concept_id' => (int) $c->id,
            ])->values()->all();
    }

    /**
     * Candidates from across the level first, the lesson's own words last.
     *
     * Same-lesson words look like the pedagogically interesting near misses, and
     * they were tried first at one point. But these books teach synonym sets a
     * lesson at a time, so "the other words in this lesson" is precisely the set
     * most likely to contain a second correct answer. Words from elsewhere at
     * the same level are the safer near miss, and the lesson's own words stay as
     * the fallback for a term the wider pool cannot supply a partner for.
     *
     * @return array<int, array{term: string, definition: ?string, module_id: ?int, concept_id: int}>
     */
    private function candidatesFor(Concept $concept, array $siblings): array
    {
        $band = $this->pool[(int) $concept->cefr_level_id] ?? [];
        $wider = [];

        if ($band !== []) {
            $count = count($band);
            $step = max(1, intdiv($count, 60));
            $offset = crc32((string) $concept->id) % $count;
            for ($i = 0; $i < 60 && count($wider) < 60; $i++) {
                $wider[] = $band[($offset + $i * $step) % $count];
            }
        }

        return array_merge(
            $wider,
            array_values(array_filter($siblings, fn ($s) => $s['concept_id'] !== (int) $concept->id)),
        );
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
     * Choose the items the placement test is allowed to measure with.
     *
     * Two rules, both learned the hard way. Only `approved` items qualify - an
     * item whose wrong answers are merely plausible must never decide someone's
     * level. And the bank is binned by ability, not by CEFR label: binning by
     * label left the old bank with nothing below -1.45, nothing above 1.36 until
     * 2.68, and no way to measure anyone sitting in those holes.
     */
    private function markPlacementBank(): void
    {
        $this->line('▸ selecting the placement item bank');
        DB::table('exercises')->update(['is_placement_eligible' => false]);

        $step = 0.5;
        $floor = -3.0;
        $ceiling = 3.0;
        $perBin = 20;

        $sameLesson = $this->itemsWithASiblingDistractor();

        // Every choice item whose wrong answers are proven wrong, with the stem
        // held to the stricter placement standard. Provenance only breaks ties:
        // a sentence the book printed as an example is preferred over one read
        // back off the page, but excluding mined stems outright left the whole
        // top of the scale empty, and an unmeasurable C1 learner is the defect
        // this bank exists to fix.
        $candidates = DB::table('exercises')
            ->join('exercise_options', 'exercise_options.exercise_id', '=', 'exercises.id')
            ->whereIn('exercises.status', Exercise::SERVABLE_STATUSES)
            ->where('exercises.validation_score', '>=', 1.0)
            ->where('exercises.generation_method', 'like', 'derived%')
            ->whereNull('exercises.deleted_at')
            ->groupBy('exercises.id', 'exercises.stem', 'exercises.difficulty', 'exercises.generation_method')
            ->havingRaw('COUNT(exercise_options.id) >= 4')
            ->havingRaw('SUM(exercise_options.is_correct) = 1')
            ->select([
                'exercises.id',
                'exercises.stem',
                'exercises.difficulty',
                'exercises.generation_method',
            ])
            ->get()
            ->filter(fn ($e) => $this->quality->isPlacementGrade($e->stem))
            ->reject(fn ($e) => in_array($e->id, $sameLesson, true))
            ->sortBy(fn ($e) => $e->generation_method === 'derived_example' ? 0 : 1)
            ->groupBy(fn ($e) => (string) floor(((float) $e->difficulty - $floor) / $step));

        $total = 0;
        $empty = [];
        $shape = [];

        for ($i = 0, $bins = (int) round(($ceiling - $floor) / $step); $i < $bins; $i++) {
            $low = $floor + $i * $step;
            $bin = $candidates->get((string) $i, collect());

            if ($bin->isEmpty()) {
                $empty[] = sprintf('%+.1f', $low);

                continue;
            }

            $ids = $bin->take($perBin)->pluck('id');
            DB::table('exercises')->whereIn('id', $ids)->update(['is_placement_eligible' => true]);
            $total += $ids->count();
            $shape[] = sprintf('%+.1f:%d', $low, $ids->count());
        }

        $this->line("   placement-eligible items: {$total}");
        $this->line('   spread: '.implode(' ', $shape));

        if ($empty !== []) {
            // A learner whose ability lands in an empty bin is measured with the
            // nearest item instead of the right one, so the gap has to be
            // visible rather than silent.
            $this->warn('   ability bins with no item: '.implode(', ', $empty));
        }

        $this->syncAssessedSkills();
    }

    /**
     * Items whose wrong answers are taught in the same lesson as the right one.
     *
     * These books teach a synonym set together - crashing and pounding waves in
     * one lesson, hassle and chore in another, coach tour and package holiday in
     * a third - so a same-lesson distractor is the one case where two options
     * routinely both fit. Definitions distinguish them well enough for practice,
     * where the learner sees the answer either way, but not well enough to
     * decide somebody's level on.
     *
     * @return array<int, int>
     */
    private function itemsWithASiblingDistractor(): array
    {
        return DB::table('exercise_options')
            ->join('exercises', 'exercises.id', '=', 'exercise_options.exercise_id')
            ->join('lesson_concept', 'lesson_concept.lesson_id', '=', 'exercises.lesson_id')
            ->join('concepts', 'concepts.id', '=', 'lesson_concept.concept_id')
            ->where('exercise_options.is_correct', false)
            ->whereColumn('concepts.label', 'exercise_options.text')
            ->distinct()
            ->pluck('exercises.id')
            ->all();
    }

    /**
     * A skill may only claim to be assessed if the bank can assess it.
     *
     * Every skill was flagged `assessed_in_placement`, but only vocabulary had
     * items. The other six opened a dimension, found nothing to ask, closed at
     * the starting prior and reported that guess as the learner's level in that
     * skill. Reporting six invented levels is worse than reporting one real one.
     */
    private function syncAssessedSkills(): void
    {
        $withItems = DB::table('exercises')
            ->where('is_placement_eligible', true)
            ->whereNotNull('skill_id')
            ->distinct()->pluck('skill_id');

        DB::table('skills')->update(['assessed_in_placement' => false]);
        if ($withItems->isNotEmpty()) {
            DB::table('skills')->whereIn('id', $withItems)->update(['assessed_in_placement' => true]);
        }

        $names = DB::table('skills')->whereIn('id', $withItems)->pluck('code')->implode(', ');
        $this->line('   skills the bank can actually assess: '.($names ?: 'none'));
    }

    private function makeExercise(
        Lesson $lesson,
        Concept $concept,
        string $templateCode,
        string $stem,
        string $instructions,
        string $status = 'draft',
        string $provenance = 'derived',
    ): Exercise {
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
                'status' => $status,
                'generation_method' => $provenance,
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
