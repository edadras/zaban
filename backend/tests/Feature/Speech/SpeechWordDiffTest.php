<?php

namespace Tests\Feature\Speech;

use App\Models\LearnerError;
use App\Services\Speech\TextTokeniser;
use App\Services\Speech\TranscriptErrorDetector;
use App\Services\Speech\WordAligner;

/**
 * The word diff is what turns "you scored 68" into "you dropped the article".
 */
class SpeechWordDiffTest extends SpeechTestCase
{
    private WordAligner $aligner;

    private TextTokeniser $tokeniser;

    private TranscriptErrorDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->aligner = app(WordAligner::class);
        $this->tokeniser = app(TextTokeniser::class);
        $this->detector = app(TranscriptErrorDetector::class);
    }

    /** @return array<int,array<string,mixed>> */
    private function diff(string $expected, string $spoken): array
    {
        return $this->aligner->align(
            $this->tokeniser->tokenise($expected),
            array_map(
                fn ($t) => $t + ['start_ms' => null, 'end_ms' => null, 'confidence' => null],
                $this->tokeniser->tokenise($spoken),
            ),
        );
    }

    public function test_an_identical_reading_produces_no_errors(): void
    {
        $rows = $this->diff('I go to the shop.', 'I go to the shop');

        $this->assertSame(
            ['correct', 'correct', 'correct', 'correct', 'correct'],
            array_column($rows, 'outcome'),
        );
        $this->assertSame([], $this->detector->detect($rows));
    }

    public function test_punctuation_and_case_do_not_count_as_differences(): void
    {
        $rows = $this->diff('The bus, however, was late!', 'the bus however was late');

        $this->assertSame(['correct', 'correct', 'correct', 'correct', 'correct'], array_column($rows, 'outcome'));
    }

    public function test_a_dropped_article_is_classified_as_an_article_error(): void
    {
        $rows = $this->diff('I take the bus to work', 'I take bus to work');

        $omitted = array_values(array_filter($rows, fn ($r) => $r['outcome'] === 'omitted'));
        $this->assertCount(1, $omitted);
        $this->assertSame('the', $omitted[0]['expected_word']);

        $findings = $this->detector->detect($rows);
        $this->assertCount(1, $findings);
        $this->assertSame('article', $findings[0]['error_type']);
        $this->assertSame('omitted_article', $findings[0]['error_subtype']);
        $this->assertSame('the', $findings[0]['expected']);
    }

    public function test_a_wrong_verb_form_is_classified_as_grammar_not_vocabulary(): void
    {
        $rows = $this->diff('I went to the shop yesterday', 'I go to the shop yesterday');

        $findings = $this->detector->detect($rows);
        $this->assertCount(1, $findings);
        $this->assertSame('grammar', $findings[0]['error_type']);
        $this->assertSame('word_form', $findings[0]['error_subtype']);
        $this->assertSame('went', $findings[0]['expected']);
        $this->assertSame('go', $findings[0]['input']);
    }

    public function test_a_regular_plural_is_also_recognised_as_a_form_error(): void
    {
        $findings = $this->detector->detect($this->diff('I have two books', 'I have two book'));

        $this->assertSame('grammar', $findings[0]['error_type']);
        $this->assertSame('word_form', $findings[0]['error_subtype']);
    }

    public function test_two_swapped_words_are_reported_once_as_word_order(): void
    {
        $findings = $this->detector->detect($this->diff('she often reads books', 'she reads often books'));

        $this->assertCount(1, $findings);
        $this->assertSame('word_order', $findings[0]['error_type']);
        $this->assertSame('swapped_words', $findings[0]['error_subtype']);
    }

    public function test_a_word_moved_across_the_sentence_is_word_order_not_a_drop_plus_an_addition(): void
    {
        $findings = $this->detector->detect($this->diff('I always drink coffee here', 'I drink coffee always here'));

        $this->assertCount(1, $findings);
        $this->assertSame('word_order', $findings[0]['error_type']);
        $this->assertSame('moved_word', $findings[0]['error_subtype']);
    }

    public function test_a_wrong_preposition_is_classified_as_a_preposition_error(): void
    {
        $findings = $this->detector->detect($this->diff('I arrive at the station', 'I arrive in the station'));

        $this->assertSame('preposition', $findings[0]['error_type']);
        $this->assertSame('wrong_preposition', $findings[0]['error_subtype']);
    }

    public function test_an_unrelated_replacement_is_a_vocabulary_confusion(): void
    {
        $findings = $this->detector->detect($this->diff('I bought a newspaper', 'I bought a sandwich'));

        $this->assertSame('vocabulary_confusion', $findings[0]['error_type']);
        $this->assertSame('wrong_word', $findings[0]['error_subtype']);
    }

    public function test_a_hesitation_sound_is_not_treated_as_a_language_error(): void
    {
        $rows = $this->diff('I go to work', 'I um go to work');

        $this->assertContains('inserted', array_column($rows, 'outcome'));
        $this->assertSame([], $this->detector->detect($rows));
    }

    public function test_open_speech_has_no_expected_words_and_no_errors(): void
    {
        $rows = $this->diff('', 'whatever I felt like saying');

        $this->assertCount(5, $rows);
        $this->assertSame(['correct', 'correct', 'correct', 'correct', 'correct'], array_column($rows, 'outcome'));
        $this->assertNull($rows[0]['expected_word']);
        $this->assertSame([], $this->detector->detect($rows));
    }

    public function test_findings_are_recorded_against_the_learner_and_repeats_increment(): void
    {
        $user = $this->learner();
        $rows = $this->diff('I take the bus to work', 'I take bus to work');

        $this->detector->record($user->id, $rows);
        $this->detector->record($user->id, $rows);

        $error = LearnerError::where('user_id', $user->id)->where('error_type', 'article')->firstOrFail();
        $this->assertSame('omitted_article', $error->error_subtype);
        $this->assertSame(2, (int) $error->occurrence_count);
        $this->assertSame(1, LearnerError::where('user_id', $user->id)->count());
    }
}
