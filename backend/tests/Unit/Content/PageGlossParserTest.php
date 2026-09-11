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

    /**
     * A page holds two or three sections, and each numbers its footnotes from
     * one. Read whole, section A's words take section B's list - the gloss the
     * book prints against "mind map" was what the card for "cram" showed.
     */
    private function twoSectionPage(): string
    {
        return implode("\n", [
            '     1          Cramming for success',
            '          A     Study and exams',
            '                Before an exam, some students cram1 for it. Even if you are a genius2,',
            '                you will have to do some revision.',
            '                1',
            '                 study in a very concentrated way for a short time 2 an exceptionally clever person',
            '',
            '          B     Academic writing',
            '                It is a good idea to start with a mind map1 when preparing an essay.',
            '                Always write a first draft2 before writing up the final version.',
            '                1',
            '                 diagram that lays out ideas for a topic 2 first, rough version',
        ]);
    }

    public function test_a_section_reads_its_own_footnotes_and_not_the_next_one_s(): void
    {
        $result = $this->parser->parse(
            $this->twoSectionPage(),
            ['cram', 'genius'],
            'A',
        );

        $this->assertSame([
            'cram' => 'study in a very concentrated way for a short time',
            'genius' => 'an exceptionally clever person',
        ], $result['glosses']);
    }

    public function test_the_later_section_reads_its_own_list_too(): void
    {
        $result = $this->parser->parse(
            $this->twoSectionPage(),
            ['mind map', 'first draft'],
            'B',
        );

        $this->assertSame([
            'mind map' => 'diagram that lays out ideas for a topic',
            'first draft' => 'first, rough version',
        ], $result['glosses']);
    }

    public function test_without_the_letter_the_page_is_still_read_whole(): void
    {
        // The books that do not letter their sections, and every page the
        // scanner set differently, have to keep working.
        $result = $this->parser->parse($this->page(), $this->terms(), null);

        $this->assertSame('hiring (new staff)', $result['glosses']['recruiting'] ?? null);
    }

    public function test_a_letter_that_is_not_on_the_page_does_not_narrow_it(): void
    {
        // Better to read too wide than to read nothing: a section that cannot
        // be found is a scanning difference, not an empty section.
        $result = $this->parser->parse($this->page(), $this->terms(), 'Q');

        $this->assertSame('improve or increase', $result['glosses']['boost'] ?? null);
    }

    public function test_it_reads_a_list_the_scanner_split_across_the_page(): void
    {
        // The margin column is set beside the prose and the reflow moves it to
        // the foot, so the list at the foot can start at 3 and the rest arrive
        // after the page number. Reading only forwards from the "1" stopped at
        // the first gap and left half the section unglossed.
        $page = implode("\n", [
            '     9          Higher study',
            '          C     Academic life',
            '                Academics carry out research1 and read journals2.',
            '                You can access it online3 or use an inter-library loan4.',
            '                3',
            '                 get hold of it on the internet 4 how libraries exchange books',
            '                8    English Vocabulary in Use',
            '                1',
            '                 less formal is do research 2 magazines with academic articles',
        ]);

        $result = $this->parser->parse(
            $page,
            ['research', 'journals', 'access it online', 'inter-library loan'],
            'C',
        );

        $this->assertSame([
            'research' => 'less formal is do research',
            'journals' => 'magazines with academic articles',
            'access it online' => 'get hold of it on the internet',
            'inter-library loan' => 'how libraries exchange books',
        ], $result['glosses']);
    }
}
