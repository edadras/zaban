<?php

namespace Tests\Unit\Content;

use App\Services\Content\PageGlossParser;
use App\Services\Content\SentenceQuality;
use App\Services\Content\SourceSentenceMiner;
use PHPUnit\Framework\TestCase;

/**
 * The page below is the real shape of a teaching page: numbered markers against
 * the taught words, and the explanations run together at the foot of the
 * section.
 */
class PageGlossParserTest extends TestCase
{
    private PageGlossParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new PageGlossParser(new SourceSentenceMiner(new SentenceQuality));
    }

    private function page(): string
    {
        return implode("\n", [
            '     4          Job interviews',
            '          A     Preparing for interviews',
            '                When companies are recruiting1, they often have a set of criteria2 to apply.',
            '                If you are shortlisted3 for an interview, do your homework first.',
            '                Ask a friend to do a trial run4 with you. This will help boost5 your confidence.',
            '                1',
            '                 hiring (new staff) 2 requirements you use to make a decision 3 selected from a',
            '                larger group 4 a practice of something new 5 improve or increase',
            '',
            '          B     During an interview',
            '                These are examples of things that might be said at a job interview.',
        ]);
    }

    /** @return array<int, string> */
    private function terms(): array
    {
        return ['recruiting', 'criteria', 'shortlisted', 'trial run', 'boost'];
    }

    public function test_it_pairs_every_footnote_with_the_word_it_explains(): void
    {
        $result = $this->parser->parse($this->page(), $this->terms());

        $this->assertSame([
            'recruiting' => 'hiring (new staff)',
            'criteria' => 'requirements you use to make a decision',
            'shortlisted' => 'selected from a larger group',
            'trial run' => 'a practice of something new',
            'boost' => 'improve or increase',
        ], $result['glosses']);
    }

    public function test_the_last_gloss_stops_where_the_next_section_starts(): void
    {
        $result = $this->parser->parse($this->page(), $this->terms());

        $this->assertSame('improve or increase', $result['glosses']['boost']);
        $this->assertStringNotContainsString('During an interview', $result['glosses']['boost']);
    }

    public function test_it_hands_back_the_footnote_block_so_it_can_be_cut_from_the_prose(): void
    {
        $result = $this->parser->parse($this->page(), $this->terms());

        $this->assertNotEmpty($result['strip']);
        $this->assertStringContainsString('hiring (new staff)', $result['strip'][0]);
        $this->assertStringNotContainsString('When companies are', $result['strip'][0]);
    }

    public function test_a_marker_attaches_to_the_whole_phrase_not_its_last_word(): void
    {
        $result = $this->parser->parse($this->page(), ['run', 'trial run']);

        $this->assertArrayHasKey('trial run', $result['glosses']);
        $this->assertSame('a practice of something new', $result['glosses']['trial run']);
    }

    public function test_a_page_with_no_markers_yields_nothing(): void
    {
        $page = 'She poured the milk into a large glass jug and left the room.';

        $this->assertSame(
            ['glosses' => [], 'strip' => []],
            $this->parser->parse($page, ['milk', 'jug']),
        );
    }

    public function test_it_ignores_a_term_whose_number_belongs_to_another_word(): void
    {
        // "criteria2" is marked; "criteria" appearing unmarked elsewhere must
        // not invent a second pairing.
        $result = $this->parser->parse($this->page(), ['criteria', 'apply']);

        $this->assertArrayNotHasKey('apply', $result['glosses']);
        $this->assertSame('requirements you use to make a decision', $result['glosses']['criteria']);
    }

    /**
     * A footnote block set in two columns: markers 1-10 down the left, 11-21
     * down the right. Read straight off the line, the two interleave and every
     * gloss lands against the wrong word.
     */
    public function test_it_reads_a_two_column_footnote_block_in_order(): void
    {
        $page = implode("\n", [
            '     2       Education: debates and issues',
            '         A   Opportunity and equality',
            '',
            '             All systems are judged on equality of opportunity1, in debates over',
            '             selective2 versus comprehensive3 schooling, and elitism4 persists.',
            '             League tables5 divide institutions. Better-off6 parents push hardest,',
            '             while the less well-off7 fall behind and few excel8 without help.',
            '',
            '          1                                                       6',
            '             when everyone has the same chances                       richer',
            '          2                                                       7',
            '             pupils are chosen for entry, usually for                 poorer',
            '             academic reasons                                      8',
            '          3                                                          achieve an excellent',
            '             everyone enters without exams                            standard',
            '          4',
            '             when you favour a small, privileged group',
            '          5',
            '             lists of schools from the best down',
        ]);

        $result = $this->parser->parse($page, [
            'equality of opportunity', 'selective', 'comprehensive', 'elitism',
            'League tables', 'better-off', 'less well-off', 'excel',
        ]);

        $this->assertSame('when everyone has the same chances', $result['glosses']['equality of opportunity']);
        $this->assertSame('richer', $result['glosses']['better-off']);
        $this->assertSame('poorer', $result['glosses']['less well-off']);
        $this->assertSame('achieve an excellent standard', $result['glosses']['excel']);
        $this->assertStringContainsString('chosen for entry', $result['glosses']['selective']);
        $this->assertStringNotContainsString('richer', $result['glosses']['equality of opportunity']);
    }

    public function test_it_survives_an_empty_page(): void
    {
        $this->assertSame(['glosses' => [], 'strip' => []], $this->parser->parse(null, ['word']));
        $this->assertSame(['glosses' => [], 'strip' => []], $this->parser->parse('text', []));
    }
}
