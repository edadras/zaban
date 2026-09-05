<?php

namespace Tests\Unit\Content;

use App\Services\Content\SentenceQuality;
use App\Services\Content\SourceSentenceMiner;
use PHPUnit\Framework\TestCase;

class SourceSentenceMinerTest extends TestCase
{
    private SourceSentenceMiner $miner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->miner = new SourceSentenceMiner(new SentenceQuality);
    }

    /**
     * The shape that produced the worst items in the bank: body text on the
     * left, margin glosses on the right, both on the same physical line.
     */
    private function twoColumnPage(): string
    {
        return implode("\n", [
            '    4          Job interviews',
            '',
            '    A     Preparing for interviews',
            '',
            '          No previous experience is necessary          opportunities for promotion',
            '          as full training will be given.              and career',
            '',
            '          If you can, ask a friend to do a trial       a practice attempt',
            '          run with you.                                before the real one',
            '',
            '          This will help boost your confidence.        increase',
        ]);
    }

    public function test_it_reads_each_column_separately(): void
    {
        $sentences = $this->miner->sentences($this->twoColumnPage());

        $this->assertContains('No previous experience is necessary as full training will be given.', $sentences);
        $this->assertContains('This will help boost your confidence.', $sentences);

        foreach ($sentences as $s) {
            $this->assertStringNotContainsString(
                'necessary opportunities',
                $s,
                'the margin column was spliced through the body text',
            );
        }
    }

    public function test_it_drops_the_superscript_gloss_markers(): void
    {
        $prose = $this->miner->reflow(
            "          When companies are recruiting1, they often have a set of criteria2 to apply.",
        );

        $this->assertStringContainsString('recruiting,', $prose);
        $this->assertStringContainsString('criteria to apply', $prose);
        $this->assertDoesNotMatchRegularExpression('/\d/', $prose);
    }

    public function test_it_does_not_glue_a_heading_to_the_sentence_below_it(): void
    {
        $page = implode("\n", [
            '     Language help',
            '     The text has some words with similar meanings connected to work.',
        ]);

        $this->assertContains(
            'The text has some words with similar meanings connected to work.',
            $this->miner->sentences($page),
        );
    }

    public function test_it_drops_a_sentence_that_swallowed_a_margin_gloss(): void
    {
        $page = '     True friends are always there when you need loyalty and honesty them.';

        $this->assertSame([], $this->miner->sentences($page, ['loyalty and honesty']));
    }

    public function test_it_removes_bracketed_inline_glosses(): void
    {
        $sentences = $this->miner->sentences(
            '     The interview may be conducted by a panel [a group of people], including your manager.',
        );

        $this->assertSame(
            ['The interview may be conducted by a panel, including your manager.'],
            $sentences,
        );
    }

    public function test_it_returns_only_the_sentences_that_use_the_term(): void
    {
        $found = $this->miner->sentencesUsing($this->twoColumnPage(), 'boost');

        $this->assertSame(['This will help boost your confidence.'], $found);
    }

    /**
     * A full-width line running past the gutter must not be chopped at it:
     * cutting mid-word turned "software development" into "software develo".
     */
    public function test_it_never_cuts_through_a_word(): void
    {
        $page = implode("\n", [
            '     Tina started her own software development business last year.',
            '     She worked long hours.                             long hours',
            '     The business grew quickly.                         became bigger',
            '     Her partner joined later.                          came to help',
            '     They opened a second office.                       another branch',
            '     Profits rose every quarter.                        went up',
            '     She sold the company.                              gave it up',
        ]);

        $sentences = $this->miner->sentences($page);

        $this->assertContains('Tina started her own software development business last year.', $sentences);
        foreach ($sentences as $s) {
            $this->assertStringNotContainsString('develo ', $s);
        }
    }

    public function test_a_single_column_page_is_left_alone(): void
    {
        $page = implode("\n", [
            'She poured the milk into a large glass jug.',
            'Everyone went straight home after the meeting.',
        ]);

        $this->assertSame(
            [
                'She poured the milk into a large glass jug.',
                'Everyone went straight home after the meeting.',
            ],
            $this->miner->sentences($page),
        );
    }
}
