<?php

namespace App\Console\Commands;

use App\Models\Lesson;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Release the corpus to learners.
 *
 * Publishing has to be a step in the pipeline, not a button somebody remembers.
 * `content:import --fresh` truncates and re-imports, and everything comes back
 * a draft — so a re-import silently withdraws the whole course from every
 * learner until this runs again. It is the last stage of the cycle for the same
 * reason `content:build-activities` is the second to last.
 *
 * The bar is the one the admin screen uses: a lesson goes out when it teaches
 * an active concept and holds at least one block that is not just the printed
 * page. Anything less is a page to read with nothing to do, and a learner sent
 * there has been sent nowhere.
 *
 * `--everything` lowers the bar to "holds a page of the book", which releases
 * the rest: the study-skills pages at the front of each book, and the sections
 * whose bold runs were all rejected as headings or as low-confidence scanning.
 * Those are real pages with real text on them and a learner can read them; what
 * they cannot do is drive a session, and they do not - the engine still asks
 * for an active concept before building an hour around a lesson.
 *
 * It is not the default because a few of those pages are what a scanner made of
 * an unreadable heading, and releasing them is a decision rather than a
 * consequence.
 */
class PublishCurriculum extends Command
{
    protected $signature = 'content:publish
                            {--everything : also release lessons whose only content is the printed page}
                            {--withdraw : take everything back to draft instead}
                            {--book= : limit to one source document id}';

    protected $description = 'Publish the imported curriculum to learners';

    /** Block types that are the page itself rather than something to do. */
    private const PASSIVE_BLOCKS = ['source_text', 'image_scene'];

    public function handle(): int
    {
        $scope = Lesson::query()
            ->whereNull('deleted_at')
            ->when($this->option('book'), fn ($q, $id) => $q->where('source_document_id', $id));

        if ($this->option('withdraw')) {
            $count = (clone $scope)->where('status', 'published')->update(['status' => 'draft']);
            $this->warn("Withdrawn: {$count} lessons are drafts again.");

            return self::SUCCESS;
        }

        $ready = $this->option('everything')
            ? DB::table('lesson_blocks')
                ->where('type', 'source_text')
                ->distinct()->pluck('lesson_id')
            : DB::table('lesson_concept')
                ->join('concepts', 'concepts.id', '=', 'lesson_concept.concept_id')
                ->where('concepts.is_active', true)
                ->distinct()->pluck('lesson_concept.lesson_id')
                ->intersect(
                    DB::table('lesson_blocks')
                        ->whereNotIn('type', self::PASSIVE_BLOCKS)
                        ->distinct()->pluck('lesson_id'),
                );

        $total = (clone $scope)->count();
        $published = 0;
        foreach ($ready->chunk(1000) as $chunk) {
            $published += (clone $scope)
                ->whereIn('id', $chunk->all())
                ->where('status', '!=', 'published')
                ->update(['status' => 'published']);
        }

        $out = (clone $scope)->where('status', 'published')->count();
        $held = $total - $out;

        $this->info("Published now: {$published}");
        $this->line("   live in total: {$out} of {$total}");

        if ($held > 0) {
            // Named, not just counted: the point of holding a lesson back is
            // that someone can go and look at the page it came from.
            $this->newLine();
            $this->warn("Held back: {$held}");
            $this->line($this->option('everything')
                ? '   These hold no page of the book at all.'
                : '   These teach nothing active, or hold the printed page and nothing else.'
                    .' `--everything` releases them as pages to read.');
            $this->table(
                ['book', 'held'],
                DB::table('lessons')
                    ->join('source_documents', 'source_documents.id', '=', 'lessons.source_document_id')
                    ->whereNotIn('lessons.id', $ready)
                    ->whereNull('lessons.deleted_at')
                    ->select('source_documents.title', DB::raw('COUNT(*) n'))
                    ->groupBy('source_documents.title')
                    ->orderByDesc('n')
                    ->get()
                    ->map(fn ($r) => [$r->title, $r->n])
                    ->all(),
            );
        }

        return self::SUCCESS;
    }
}
