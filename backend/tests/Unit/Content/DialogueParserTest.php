<?php

namespace Tests\Unit\Content;

use App\Services\Content\DialogueParser;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Every case here is a shape that actually appears in the source books and that
 * cost real dialogue turns before it was handled.
 */
class DialogueParserTest extends TestCase
{
    private DialogueParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new DialogueParser;
    }

    public function test_blank_lines_between_turns_do_not_end_the_exchange(): void
    {
        // The typesetting separates every turn with a blank line. Treating that
        // as the end of the run left 328 one-turn "dialogues" and stored none
        // of them.
        $runs = $this->parser->runsIn(<<<'TXT'
     A: So, can you talk us through your CV?

         B: Well, I studied Engineering.

     A: And what about training?

         B: We have a good programme.
TXT);

        $this->assertCount(1, $runs);
        $this->assertCount(4, $runs[0]);
    }

    public function test_a_wrapped_turn_is_joined_to_the_turn_it_belongs_to(): void
    {
        $runs = $this->parser->runsIn(<<<'TXT'
     A: Where did you work before?
         B: I took a job as a trainee at F3
            Telecom.
TXT);

        $this->assertCount(1, $runs);
        $this->assertSame('I took a job as a trainee at F3 Telecom.', $runs[0][1]['text']);
    }

    public function test_body_text_after_a_finished_turn_is_not_swallowed_into_it(): void
    {
        // The previous turn ends on a question mark, so the indented prose that
        // follows is the book talking, not more speech.
        $runs = $this->parser->runsIn(<<<'TXT'
     A: Are you ready?
         B: Yes, I am.
            Notice that we often use the present continuous here.
TXT);

        $this->assertSame('Yes, I am.', $runs[0][1]['text']);
    }

    public function test_section_headings_are_not_mistaken_for_speakers(): void
    {
        // These books letter their sections A, B, C. Without the colon
        // requirement every section heading became a speaker.
        $runs = $this->parser->runsIn(<<<'TXT'
      A      Preparing for interviews
      B      Adjectives connected with size
TXT);

        $this->assertSame([], $runs);
    }

    public function test_a_lone_example_line_is_not_a_dialogue(): void
    {
        $runs = $this->parser->runsIn("     A: I'd like to book a table for two, please.\n");

        $this->assertSame([], $runs, 'one turn from one speaker is an example, not an exchange');
    }

    public function test_a_run_needs_two_different_speakers(): void
    {
        $runs = $this->parser->runsIn("  A: First point.\n  A: Second point.\n");

        $this->assertSame([], $runs);
    }

    /**
     * The PDF flattened superscript footnote markers into the words. A digit
     * glued to a lowercase word is always one of those; digits after a space or
     * a capital are real and must survive.
     */
    #[DataProvider('footnoteProvider')]
    public function test_it_strips_footnote_markers_without_eating_real_numbers(string $in, string $expected): void
    {
        $this->assertSame($expected, $this->parser->cleanTurn($in));
    }

    public static function footnoteProvider(): array
    {
        return [
            'mid-sentence' => ['can you talk us through1 your CV?', 'can you talk us through your CV?'],
            'before a full stop' => ['professional development3.', 'professional development.'],
            'two digits' => ['new recruits12.', 'new recruits.'],
            'real clock time survives' => ['the meeting was at 10.30.', 'the meeting was at 10.30.'],
            'capital-letter code survives' => ['a trainee at F3 Telecom.', 'a trainee at F3 Telecom.'],
            'standalone number survives' => ['I need 2 tickets.', 'I need 2 tickets.'],
        ];
    }

    public function test_bracketed_glosses_are_removed_from_speech(): void
    {
        // "[giving a big smile]" is the book explaining a word, not something a
        // person says out loud. It is already captured as an inline gloss.
        $this->assertSame(
            'What are you grinning at?',
            $this->parser->cleanTurn('What are you grinning at? [giving a big smile]'),
        );
    }
}
