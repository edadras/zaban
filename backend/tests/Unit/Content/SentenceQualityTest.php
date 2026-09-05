<?php

namespace Tests\Unit\Content;

use App\Services\Content\SentenceQuality;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every rejected string below is a real stem that shipped in the exercise bank
 * and that a fluent speaker could not answer.
 */
class SentenceQualityTest extends TestCase
{
    private SentenceQuality $quality;

    protected function setUp(): void
    {
        parent::setUp();
        $this->quality = new SentenceQuality;
    }

    public static function rejected(): array
    {
        return [
            'fragment cut at a column edge' => ['a toy for dogs'],
            'truncated mid-clause' => ['Mia is a very good flute-player. She plays in an orchestra. Her friend, Nuria, is a good'],
            'two sentences glued' => ['After this long, cold winter, I am longing for spring. He will never stop yearning for his country.'],
            'no punctuation at all' => ['Sometimes I listen to the Sometimes I read a'],
            'book talking about English' => ['Actually is a false friend in some languages - in English it means in reality NOT now.'],
            'book talking about stress' => ['The main stress is usually on the first part.'],
            'dictionary prose' => ['Someone who sees a crime being committed.'],
            'extraction debris' => ['He was ***completely*** exhausted after the race.'],
            'ellipsis debris' => ['She opened the door and ... walked away slowly.'],
            'e.g. notation' => ['Compound nouns, e.g. haircut, are stressed on the first part.'],
            'slash alternatives' => ['He was wound up / stressed out / on edge about it.'],
            'too short' => ['Yes, please.'],
            'starts mid-clause' => ['and then she left the room without saying anything.'],
        ];
    }

    #[DataProvider('rejected')]
    public function test_it_rejects_what_a_learner_cannot_answer(string $text): void
    {
        $this->assertFalse(
            $this->quality->isUsableSentence($text),
            "This shipped as an exercise stem and should not have: {$text}",
        );
    }

    public static function accepted(): array
    {
        return [
            'plain declarative' => ['She poured the milk into a large glass jug.'],
            'question' => ['Do you take sugar in tea or coffee?'],
            'opens with a quote' => ['"I have never been there," he admitted quietly.'],
            'idiom in use' => ['I hope they will make a go of the business but they are taking a big risk.'],
            'one internal comma' => ['After the meeting, everyone went straight home.'],
        ];
    }

    #[DataProvider('accepted')]
    public function test_it_keeps_real_sentences(string $text): void
    {
        $this->assertTrue(
            $this->quality->isUsableSentence($text),
            "This is a usable sentence and was rejected: {$text}",
        );
    }

    public function test_it_blanks_every_occurrence_of_the_term(): void
    {
        $stem = $this->quality->blank(
            'She returned the book, then borrowed the same book again.',
            'book',
        );

        $this->assertNotNull($stem);
        $this->assertStringNotContainsString('book', $stem);
        $this->assertSame(2, substr_count($stem, '______'));
    }

    public function test_it_will_not_blank_a_term_inside_another_word(): void
    {
        // "art" lives inside "start"; blanking it there yields "st______ed".
        $this->assertFalse($this->quality->containsTerm('They started the engine.', 'art'));
        $this->assertNull($this->quality->blank('They started the engine.', 'art'));
    }

    public function test_it_matches_a_multi_word_phrase_as_a_unit(): void
    {
        $this->assertTrue($this->quality->containsTerm('They will make a go of it.', 'make a go of'));
        $this->assertSame(
            'They will ______ it.',
            $this->quality->blank('They will make a go of it.', 'make a go of'),
        );
    }

    public function test_it_refuses_a_stem_that_is_mostly_blank(): void
    {
        $this->assertNull($this->quality->blank('Good morning.', 'Good morning'));
    }

    public function test_it_recognises_glued_sentences(): void
    {
        $this->assertTrue($this->quality->isGlued('He left. She stayed.'));
        $this->assertFalse($this->quality->isGlued('He left with Mr. Smith.'));
        $this->assertFalse($this->quality->isGlued('She stayed behind.'));
    }
}
