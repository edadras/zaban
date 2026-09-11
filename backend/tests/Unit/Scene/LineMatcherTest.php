<?php

namespace Tests\Unit\Scene;

use App\Services\Scene\LineMatcher;
use PHPUnit\Framework\TestCase;

/**
 * What counts as having said the line.
 *
 * The cases below are the ones that decide whether a learner trusts the thing:
 * a contraction is not a mistake, a missing full stop is not a mistake, a
 * mis-heard letter in a long word is not a mistake, and leaving out the word
 * the sentence was about is.
 */
class LineMatcherTest extends TestCase
{
    private LineMatcher $matcher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->matcher = new LineMatcher;
    }

    public function test_the_line_said_exactly_is_full_marks(): void
    {
        $result = $this->matcher->match('I have a booking for three nights.', ['I have a booking for three nights.']);

        $this->assertSame(100, $result['similarity']);
        $this->assertTrue($result['accepted']);
        $this->assertSame([], $result['missing']);
    }

    public function test_contractions_are_the_same_sentence(): void
    {
        $result = $this->matcher->match("I've had a sore throat since Monday", ['I have had a sore throat since Monday.']);

        $this->assertSame(100, $result['similarity']);
        $this->assertTrue($result['accepted']);
    }

    public function test_punctuation_and_case_are_not_the_lesson(): void
    {
        $result = $this->matcher->match('yes i have a booking', ['Yes, I have a booking!']);

        $this->assertTrue($result['accepted']);
    }

    public function test_a_recogniser_mishearing_one_letter_of_a_long_word_still_counts(): void
    {
        $result = $this->matcher->match('I need to see the docter', ['I need to see the doctor']);

        $this->assertTrue($result['accepted']);
    }

    public function test_a_one_letter_difference_in_a_short_word_is_a_different_word(): void
    {
        // "he" and "we" are not a mishearing to forgive: they change who did it.
        $result = $this->matcher->match('we are late', ['he is late']);

        $this->assertFalse($result['accepted']);
    }

    public function test_the_words_left_out_are_reported(): void
    {
        $result = $this->matcher->match('I have a booking', ['I have a booking for three nights']);

        $this->assertFalse($result['accepted']);
        $this->assertSame(['for', 'three', 'nights'], $result['missing']);
    }

    public function test_words_that_were_not_in_the_line_are_reported_too(): void
    {
        $result = $this->matcher->match('I really have a booking today', ['I have a booking']);

        $this->assertContains('really', $result['extra']);
        $this->assertContains('today', $result['extra']);
    }

    public function test_the_best_of_several_accepted_wordings_wins(): void
    {
        $result = $this->matcher->match('Can I have the bill please', [
            'Could I have the bill, please?',
            'Can I have the bill, please?',
        ]);

        $this->assertSame(100, $result['similarity']);
        $this->assertSame('Can I have the bill, please?', $result['matched']);
    }

    public function test_saying_a_repeated_word_once_does_not_satisfy_saying_it_twice(): void
    {
        $result = $this->matcher->match('it is very good', ['it is very very good']);

        $this->assertContains('very', $result['missing']);
    }

    public function test_an_empty_answer_scores_nothing_rather_than_crashing(): void
    {
        $result = $this->matcher->match('', ['I have a booking']);

        $this->assertSame(0, $result['similarity']);
        $this->assertFalse($result['accepted']);
    }

    public function test_almost_is_reported_separately_from_wrong(): void
    {
        // Six of the ten words: recognisably the right sentence, and not yet
        // the sentence.
        $close = $this->matcher->match(
            'I would rather have a refund',
            ['I would rather have a refund if that is possible'],
        );

        $this->assertFalse($close['accepted']);
        $this->assertTrue($close['close'], 'A near miss should be told apart from a wrong answer.');
    }
}
