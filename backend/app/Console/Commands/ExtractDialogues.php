<?php

namespace App\Console\Commands;

use App\Models\Character;
use App\Models\Dialogue;
use App\Models\DialogueTurn;
use App\Models\Lesson;
use Illuminate\Console\Command;
use App\Services\Content\DialogueParser;
use Illuminate\Support\Facades\DB;

/**
 * Recovers the two-speaker exchanges buried in the source pages.
 *
 * These books are vocabulary references, so dialogue is not their backbone -
 * but 600-odd genuine turns are in there, printed as "A: ... B: ...", and they
 * are the only material in the corpus that shows the language actually being
 * spoken between two people. Left as a run of lines inside a page blob they are
 * unusable; as dialogue rows they can be role-played, voiced per character, and
 * turned into listening exercises.
 */
class ExtractDialogues extends Command
{
    protected $signature = 'content:dialogues
        {--dry-run : report what would be extracted without writing}';

    protected $description = 'Extract printed A/B dialogues into dialogues and dialogue_turns';

    /**
     * Which cast member plays which part, chosen from the lesson's subject so a
     * hotel exchange gets the receptionist and a clinic exchange gets the
     * doctor. Deterministic, so re-running never reshuffles the cast.
     *
     * @var array<string,array{0:string,1:string}>
     */
    private const ROLE_HINTS = [
        'hotel' => ['daniel', 'grace'],
        'room' => ['daniel', 'grace'],
        'shop' => ['maya', 'tomas'],
        'buy' => ['maya', 'tomas'],
        'money' => ['maya', 'tomas'],
        'price' => ['maya', 'tomas'],
        'food' => ['maya', 'ines'],
        'restaurant' => ['daniel', 'ines'],
        'cook' => ['maya', 'ines'],
        'health' => ['maya', 'aiko'],
        'doctor' => ['daniel', 'aiko'],
        'illness' => ['maya', 'aiko'],
        'body' => ['maya', 'clara'],
        'work' => ['daniel', 'lena'],
        'job' => ['daniel', 'lena'],
        'interview' => ['daniel', 'lena'],
        'career' => ['maya', 'lena'],
        'office' => ['maya', 'lena'],
        'study' => ['nadia', 'omar'],
        'education' => ['nadia', 'omar'],
        'school' => ['joseph', 'omar'],
        'exam' => ['nadia', 'omar'],
        'travel' => ['maya', 'peter'],
        'transport' => ['daniel', 'peter'],
        'train' => ['maya', 'peter'],
        'direction' => ['daniel', 'peter'],
        'family' => ['maya', 'samuel'],
        'home' => ['maya', 'samuel'],
        'house' => ['daniel', 'samuel'],
        'weather' => ['daniel', 'samuel'],
        'repair' => ['maya', 'raj'],
        'machine' => ['daniel', 'raj'],
        'technology' => ['nadia', 'raj'],
    ];

    private const DEFAULT_PAIR = ['maya', 'daniel'];

    public function handle(DialogueParser $parser): int
    {
        $cast = Character::pluck('id', 'slug');

        if ($cast->isEmpty()) {
            $this->error('No cast to assign turns to. Run db:seed --class=CastSeeder first.');

            return self::FAILURE;
        }

        /*
         * Lesson pages, per document, in page order. A unit runs across several
         * pages while only its first is recorded, so a dialogue printed on page
         * 47 belongs to the lesson that started on 46 - matching the exact page
         * alone threw away nearly half of what the parser found.
         */
        $lessonsByDocument = Lesson::whereNotNull('source_page')
            ->orderBy('source_page')->orderBy('id')
            ->get(['id', 'title', 'unit_id', 'cefr_level_id', 'source_document_id', 'source_page'])
            ->groupBy('source_document_id');

        $languages = DB::table('source_documents')->pluck('language_id', 'id')->all();

        $pages = DB::table('source_pages')
            ->join('source_files', 'source_files.id', '=', 'source_pages.source_file_id')
            ->select([
                'source_pages.page_number',
                'source_pages.text',
                'source_files.source_document_id',
            ])
            ->orderBy('source_pages.id')
            ->get();

        $dialogues = 0;
        $turns = 0;
        $unmatched = 0;

        foreach ($pages as $page) {
            foreach ($parser->runsIn((string) $page->text) as $sequence => $run) {
                $lesson = $this->lessonForPage(
                    $lessonsByDocument->get($page->source_document_id),
                    (int) $page->page_number,
                );

                if (! $lesson) {
                    $unmatched++;

                    continue;
                }

                $dialogues++;
                $turns += count($run);

                if ($this->option('dry-run')) {
                    continue;
                }

                $this->persist($lesson, $page, $run, $cast, $languages[$page->source_document_id] ?? 1, $sequence);
            }
        }

        $this->info("{$dialogues} dialogue(s), {$turns} turn(s)"
            .($unmatched ? ", {$unmatched} run(s) on pages with no lesson" : ''));

        if ($this->option('dry-run')) {
            $this->line('Dry run - nothing written.');
        }

        return self::SUCCESS;
    }

    /**
     * The lesson this page's content belongs to: the last one that starts at or
     * before it. Bounded to a few pages so a dialogue on an appendix page does
     * not get attributed to a lesson forty pages earlier.
     */
    private function lessonForPage($lessons, int $page): ?Lesson
    {
        if (! $lessons) {
            return null;
        }

        $match = $lessons->last(fn ($l) => $l->source_page <= $page);

        return $match && ($page - $match->source_page) <= 3 ? $match : null;
    }

    private function persist(Lesson $lesson, $page, array $run, $cast, int $languageId, int $sequence): void
    {
        DB::transaction(function () use ($lesson, $page, $run, $cast, $languageId, $sequence) {
            $dialogue = Dialogue::updateOrCreate(
                [
                    'source_document_id' => $page->source_document_id,
                    'source_page' => $page->page_number,
                    'source_sequence' => $sequence,
                ],
                [
                    'title' => $lesson->title,
                    'language_id' => $languageId,
                    'cefr_level_id' => $lesson->cefr_level_id,
                    'setting' => $lesson->title,
                    'summary' => $this->summarise($run),
                    'generation_method' => 'extracted',
                    'copyright_status' => 'owned',
                ],
            );

            // Rebuild the turns rather than diffing them: a re-extraction means
            // the parse changed, and half-updated turns would be worse than none.
            DialogueTurn::where('dialogue_id', $dialogue->id)->delete();

            $pair = $this->castFor($lesson->title, $cast);
            $speakers = [];

            foreach ($run as $i => $turn) {
                $speakers[$turn['speaker']] ??= $pair[count($speakers) % count($pair)];

                DialogueTurn::create([
                    'dialogue_id' => $dialogue->id,
                    'character_id' => $speakers[$turn['speaker']],
                    'position' => $i,
                    'text' => $turn['text'],
                    // Every turn is learnable material; which side the learner
                    // takes is a runtime choice, not a property of the text.
                    'is_learner_turn' => false,
                ]);
            }
        });
    }

    private function summarise(array $run): string
    {
        return \Illuminate\Support\Str::limit(
            collect($run)->pluck('text')->implode(' '),
            400,
        );
    }

    /** @return list<int> character ids, in speaker order */
    private function castFor(string $title, $cast): array
    {
        $haystack = mb_strtolower($title);

        foreach (self::ROLE_HINTS as $keyword => $slugs) {
            if (str_contains($haystack, $keyword)) {
                $ids = array_values(array_filter(array_map(fn ($s) => $cast[$s] ?? null, $slugs)));

                if (count($ids) === count($slugs)) {
                    return $ids;
                }
            }
        }

        return array_values(array_filter(array_map(fn ($s) => $cast[$s] ?? null, self::DEFAULT_PAIR)));
    }
}
