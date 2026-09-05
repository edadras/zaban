<?php

namespace Tests\Feature\Content;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A book's label must keep naming the book.
 *
 * These were once relabelled Foundation / Core / Advancing / Mastery. Two
 * consequences, both silent: searching the corpus for "elementary" or
 * "advanced" - the words in the files' own names - matched nothing, so books
 * holding thousands of senses read as empty; and "Advancing" was the
 * upper-intermediate book, so a search for "Advanc" returned a different book
 * without saying so.
 *
 * Nothing owned those names, which is why they could drift. These tests own
 * them now. They are skipped where the corpus has not been imported, because a
 * fresh checkout has no documents to check.
 */
class SourceDocumentLabellingTest extends TestCase
{
    /**
     * The corpus lives in the application database, not the scratch database
     * the rest of the suite drops and rebuilds. Reading it through its own
     * connection is what lets these tests run under a plain `phpunit`: the
     * alternative - pointing DB_DATABASE at the corpus for the run - hands the
     * real database to every RefreshDatabase test in the suite.
     */
    private function corpus(): ConnectionInterface
    {
        $connection = DB::connection('content');

        try {
            $connection->getPdo();
        } catch (\Throwable $e) {
            $this->markTestSkipped('The content database is not reachable here: '.$e->getMessage());
        }

        return $connection;
    }

    /** source pdf => the level word its title must contain */
    public static function bookProvider(): array
    {
        return [
            'elementary' => ['elementary_3rd.pdf', 'Elementary', ['A1', 'A2']],
            'pre-int/int' => ['pre_intermediate_intermediate_4th.pdf', 'Pre-intermediate', ['A2', 'B1']],
            'upper-int' => ['upper_intermediate_4th.pdf', 'Upper-intermediate', ['B1', 'B2']],
            'advanced' => ['advanced_3rd.pdf', 'Advanced', ['C1', 'C2']],
        ];
    }

    #[DataProvider('bookProvider')]
    public function test_a_book_is_named_after_the_level_it_teaches(string $file, string $level, array $cefr): void
    {
        $row = $this->corpus()->table('source_files')
            ->join('source_documents', 'source_documents.id', '=', 'source_files.source_document_id')
            ->leftJoin('cefr_levels', 'cefr_levels.id', '=', 'source_documents.cefr_level_id')
            ->where('source_files.original_name', $file)
            ->select('source_documents.title', 'cefr_levels.code')
            ->first();

        if (! $row) {
            $this->markTestSkipped("{$file} is not imported in this environment.");
        }

        $this->assertStringContainsString(
            $level,
            $row->title,
            "{$file} is titled \"{$row->title}\" — a reader searching for \"{$level}\" would not find it",
        );

        $this->assertContains(
            $row->code,
            $cefr,
            "{$file} is pitched at {$row->code}, which is not a level this book teaches",
        );
    }

    public function test_no_two_books_share_a_title(): void
    {
        $titles = $this->corpus()->table('source_documents')->pluck('title');

        if ($titles->isEmpty()) {
            $this->markTestSkipped('No corpus imported in this environment.');
        }

        $this->assertSame(
            $titles->count(),
            $titles->unique()->count(),
            'two documents share a title, so neither can be identified by name',
        );
    }

    public function test_a_level_word_identifies_exactly_one_book(): void
    {
        // The real failure was "Advancing" answering a search for "Advanc"
        // while being a different book from the one the reader meant.
        $titles = $this->corpus()->table('source_documents')->pluck('title');

        if ($titles->isEmpty()) {
            $this->markTestSkipped('No corpus imported in this environment.');
        }

        foreach (['Elementary', 'Upper-intermediate', 'Advanced'] as $word) {
            $matches = $titles->filter(fn ($t) => str_contains($t, $word));

            $this->assertLessThanOrEqual(
                1,
                $matches->count(),
                "\"{$word}\" matches more than one book: ".$matches->implode(', '),
            );
        }
    }

    public function test_every_book_actually_carries_vocabulary(): void
    {
        // The report that started this was "two books have zero senses". They
        // did not - but if one ever genuinely does, that should fail loudly
        // rather than be discovered by eye.
        $rows = $this->corpus()->table('source_documents')
            ->select('source_documents.id', 'source_documents.title')
            ->selectRaw('(
                select count(distinct vocabulary_senses.id)
                from lessons
                join lesson_concept on lesson_concept.lesson_id = lessons.id
                join concepts on concepts.id = lesson_concept.concept_id
                    and concepts.conceptable_type = ?
                join vocabulary_senses on vocabulary_senses.id = concepts.conceptable_id
                where lessons.source_document_id = source_documents.id
            ) as senses', ['App\Models\VocabularySense'])
            ->get();

        if ($rows->isEmpty()) {
            $this->markTestSkipped('No corpus imported in this environment.');
        }

        foreach ($rows as $row) {
            $this->assertGreaterThan(
                0,
                (int) $row->senses,
                "\"{$row->title}\" has no vocabulary attached to any of its lessons",
            );
        }
    }

    /**
     * Vocabulary comes from the bold runs on a page, and typography makes
     * things bold for reasons that have nothing to do with language. Each class
     * below reached the vocabulary table as a headword before it was filtered,
     * several of them carrying definitions.
     */
    public function test_no_headword_is_typographic_debris(): void
    {
        $items = $this->corpus()->table('vocabulary_items')->pluck('headword');

        if ($items->isEmpty()) {
            $this->markTestSkipped('No corpus imported in this environment.');
        }

        // Bullets, brackets, currency symbols, bare figures: a bullet, a pound
        // sign, "2%", the cross-reference "1.1".
        $noLetters = $items->filter(fn ($h) => ! preg_match('/[A-Za-z]/', $h));
        $this->assertSame([], $noLetters->values()->all(), 'headwords with no letter in them');

        // The p, i, n of a spelled-out PIN. "I" is a word; lowercase "i" is not.
        $lone = $items->filter(
            fn ($h) => mb_strlen($h) === 1 && ! in_array($h, ['a', 'A', 'I'], true),
        );
        $this->assertSame([], $lone->values()->all(), 'single letters that are not words');

        // "+ -ing" is the books' notation for what a pattern takes.
        $notation = $items->filter(fn ($h) => str_starts_with($h, '+'));
        $this->assertSame([], $notation->values()->all(), 'grammar-pattern notation as vocabulary');
    }

    /**
     * The PDFs set fi/fl/ff as single glyphs and pdftotext leaves a space
     * behind them, so "benefits" arrives as "benefi ts" - a real word rendered
     * unsearchable and unmatchable.
     */
    public function test_no_ligature_glyph_survives_into_the_content(): void
    {
        $ligatures = 'ﬀﬁﬂﬃﬄ';

        $checks = [
            'vocabulary_items' => 'headword',
            'definitions' => 'text',
            'examples' => 'text',
            'lessons' => 'summary',
        ];

        if ($this->corpus()->table('vocabulary_items')->doesntExist()) {
            $this->markTestSkipped('No corpus imported in this environment.');
        }

        foreach ($checks as $table => $column) {
            $rows = $this->corpus()->table($table)
                ->where($column, 'REGEXP', '['.$ligatures.']')
                ->limit(3)
                ->pluck($column);

            $this->assertSame(
                [],
                $rows->values()->all(),
                "{$table}.{$column} still contains unexpanded ligature glyphs",
            );
        }
    }
}
