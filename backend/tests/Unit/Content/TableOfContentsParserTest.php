<?php

namespace Tests\Unit\Content;

use App\Services\Content\TableOfContentsParser;
use Tests\TestCase;

/**
 * Each fixture is the shape of a real contents page in one of the source books,
 * reduced to the feature that broke the parse.
 */
class TableOfContentsParserTest extends TestCase
{
    private TableOfContentsParser $parser;

    private const COLUMN_WIDTH = 49;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new TableOfContentsParser;
    }

    /**
     * Lay two columns out at a fixed width, the way the books print them and the
     * way the PDF extractor flattens them into single lines.
     *
     * @param  list<array{0:string,1:string}>  $rows
     */
    private function page(array $rows, int $indent = 0): string
    {
        $pad = str_repeat(' ', $indent);

        return implode("\n", array_map(
            fn ($row) => $pad.str_pad($row[0], self::COLUMN_WIDTH).$row[1],
            $rows,
        ));
    }

    private function twoColumnPage(int $indent = 0): string
    {
        return $this->page([
            ['Contents', ''],
            ['Thanks                                    5', 'Travel'],
            ['Introduction                              6', '25 On the road: traffic           56'],
            ['Work and study', '26 Travel and accommodation       58'],
            [' 1 Cramming for success: study and', '27 Attracting tourists            60'],
            ['      academic work                       8', ''],
            [' 2    Education: debates and issues      10', 'The environment'],
            [' 3    Applying for a job                 12', '28 Describing the world           62'],
            [' 4    Job interviews                     14', '29 Weather and climate            64'],
            [' 5    At work: colleagues                16', '30 The animal kingdom             66'],
            [' 6    At work: job satisfaction          18', '31 Our endangered world          68'],
            [' 7    At work: careers                   20', '32 Green issues                   70'],
            ['People and relationships', '33 Conservation                   72'],
            [' 8    Describing people                  22', ''],
            [' 9    Relationships                      24', ''],
        ], $indent);
    }

    public function test_it_recovers_the_categories_and_where_each_starts(): void
    {
        $this->assertSame(
            [
                ['theme' => 'Work and study', 'first_unit' => 1],
                ['theme' => 'People and relationships', 'first_unit' => 8],
                ['theme' => 'Travel', 'first_unit' => 25],
                ['theme' => 'The environment', 'first_unit' => 28],
            ],
            $this->parser->parse([$this->twoColumnPage()], 60),
        );
    }

    public function test_a_wrapped_unit_title_is_not_read_as_a_category(): void
    {
        // "academic work   8" is the second line of unit 1's title. Read as a
        // heading it invents a category in the middle of Work and study.
        $themes = array_column($this->parser->parse([$this->twoColumnPage()], 60), 'theme');

        $this->assertNotContains('academic work', $themes);
    }

    public function test_an_indented_continuation_page_parses_the_same(): void
    {
        // The books indent their second contents page four spaces further than
        // their first. Measured absolutely rather than against the column's own
        // margin, every heading there looks indented - so it looks like a
        // wrapped title, and the last real category swallows the rest of the book.
        $this->assertSame(
            $this->parser->parse([$this->twoColumnPage()], 60),
            $this->parser->parse([$this->twoColumnPage(4)], 60),
        );
    }

    public function test_front_matter_is_not_mistaken_for_a_category(): void
    {
        $themes = array_column($this->parser->parse([$this->twoColumnPage()], 60), 'theme');

        $this->assertNotContains('Contents', $themes);
        $this->assertNotContains('Thanks', $themes);
        $this->assertNotContains('Introduction', $themes);
    }

    public function test_it_stops_at_the_end_of_the_contents(): void
    {
        // Page two here is the introduction. Parsing on into it invents
        // categories out of numbered prose.
        $prose = "Introduction\n\nThis book is for learners who want to build\n"
            ."their vocabulary. There are 100 units in it.\n";

        $themes = array_column($this->parser->parse([$this->twoColumnPage(), $prose], 60), 'theme');

        $this->assertSame(
            ['Work and study', 'People and relationships', 'Travel', 'The environment'],
            $themes,
        );
    }

    /**
     * The sanity gate. A wrong category is a navigational lie, while the
     * mechanical "Units 1-10" grouping it would replace is merely dull - so a
     * parse that fails these checks must be discarded rather than applied.
     */
    public function test_the_gate_rejects_a_single_category_swallowing_the_book(): void
    {
        $this->assertFalse($this->parser->isCoherent(
            [['theme' => 'Everything', 'first_unit' => 1]],
            40,
        ));
    }

    public function test_the_gate_rejects_a_book_that_does_not_start_at_the_beginning(): void
    {
        // Categories only from unit 51 on means the first contents page was
        // missed entirely.
        $this->assertFalse($this->parser->isCoherent([
            ['theme' => 'Health', 'first_unit' => 51],
            ['theme' => 'Technology', 'first_unit' => 55],
            ['theme' => 'Words and meanings', 'first_unit' => 85],
        ], 100));
    }

    public function test_the_gate_rejects_two_categories_claiming_the_same_start(): void
    {
        $this->assertFalse($this->parser->isCoherent([
            ['theme' => 'Work', 'first_unit' => 1],
            ['theme' => 'Travel', 'first_unit' => 1],
            ['theme' => 'People', 'first_unit' => 20],
        ], 60));
    }

    public function test_the_gate_accepts_a_well_formed_grouping(): void
    {
        $this->assertTrue($this->parser->isCoherent([
            ['theme' => 'Work and study', 'first_unit' => 1],
            ['theme' => 'People and relationships', 'first_unit' => 8],
            ['theme' => 'Travel', 'first_unit' => 25],
            ['theme' => 'The environment', 'first_unit' => 40],
        ], 60));
    }
}
