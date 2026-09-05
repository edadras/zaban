<?php

namespace App\Services\Content;

/**
 * Decides whether a string pulled out of a book is a sentence a learner can be
 * shown, and blanks a term inside one safely.
 *
 * The importer harvested examples from running book text, and a large share of
 * what it caught are not sentences: fragments cut at a column edge ("a toy for
 * dogs"), two sentences glued where the PDF lost a line break ("Sometimes I
 * listen to the Sometimes I read a"), and prose where the book talks *about*
 * English rather than using it ("______ is a false friend in some languages").
 *
 * All three produce exercises that a fluent speaker cannot answer, which is how
 * a C1 learner ends up placed at A1. The gate is deliberately strict: an item
 * not built is a much smaller harm than an item that punishes a correct answer.
 */
class SentenceQuality
{
    private const MIN_CHARS = 15;
    private const MAX_CHARS = 220;
    private const MIN_WORDS = 4;

    /** Words that must survive alongside the blank for the gap to be inferable. */
    private const MIN_CONTEXT_WORDS = 3;

    /**
     * Debris the PDF extraction leaves behind, and notation the books use to
     * talk about words rather than with them.
     */
    private const DEBRIS = '/[*=\[\]{}<>|~]|\.{3}|\x{2026}|e\.g\.|i\.e\.|etc\./u';

    /**
     * The book explaining a word. Grammatically fine, useless as a question:
     * the answer is the sentence's own subject.
     */
    private const METALINGUISTIC = '/\b(
          means|meaning|refers?\s+to|is\s+another\s+way\s+of\s+saying|way\s+of\s+saying
        | is\s+the\s+opposite\s+of|opposite\s+of|synonym|antonym|false\s+friend
        | is\s+(?:more|less)\s+(?:formal|informal|common)|rather\s+formal|informal
        | the\s+main\s+stress|is\s+stressed|pronounced|spelt|spelled
        | in\s+English|in\s+American\s+English|in\s+British\s+English
        | is\s+used\s+(?:when|to|for|in)|we\s+(?:say|use)|you\s+(?:say|use)
        | note\s+that|compare|see\s+unit
    )\b/xiu';

    /** Full stops that end an abbreviation rather than a sentence. */
    private const ABBREVIATIONS = '/\b(Mr|Mrs|Ms|Dr|Prof|St|Ave|Rd|Jr|Sr|vs|approx|No)\./u';

    /** Dictionary prose: a definition wearing a sentence's clothes. */
    private const DEFINITION_PROSE = '/^(If\s+you|When\s+you|Someone\s+who|Somebody\s+who|Something\s+that|A\s+person\s+who|People\s+who|Used\s+to|This\s+means|The\s+act\s+of)\b/iu';

    public function isUsableSentence(?string $text): bool
    {
        $t = trim((string) $text);

        if (mb_strlen($t) < self::MIN_CHARS || mb_strlen($t) > self::MAX_CHARS) {
            return false;
        }

        if (preg_match(self::DEBRIS, $t)) {
            return false;
        }

        // A sentence opens with a capital; a fragment usually opens mid-clause.
        if (! preg_match('/^[A-Z"\x{2018}\x{201C}]/u', $t)) {
            return false;
        }

        // ... and closes. A stem cut off at a column edge does not, which is
        // what catches most of the truncated extractions.
        if (! preg_match('/[.!?"\x{2019}\x{201D}]$/u', $t)) {
            return false;
        }

        if ($this->isGlued($t)) {
            return false;
        }

        if (preg_match(self::DEFINITION_PROSE, $t)) {
            return false;
        }

        if ($this->isMetalinguistic($t)) {
            return false;
        }

        if ($this->hasSpliceArtefact($t)) {
            return false;
        }

        // Slash-separated alternatives are a lexis note, not a sentence.
        if (substr_count($t, '/') > 1) {
            return false;
        }

        return str_word_count($t) >= self::MIN_WORDS;
    }

    /**
     * Is this cloze stem clean enough to measure someone's level with?
     *
     * A practice item can afford to be a bit ragged - the learner sees the
     * answer afterwards either way. A placement item cannot: it is one of forty
     * questions deciding where somebody starts, so anything that could be missed
     * for a reason other than not knowing the word has to go. That means a short
     * single clause, one gap, and enough sentence on both sides of the gap for
     * the answer to be inferable.
     */
    public function isPlacementGrade(?string $stem, string $placeholder = '______'): bool
    {
        $t = trim((string) $stem);

        if (! $this->isUsableSentence(str_replace($placeholder, 'thing', $t))) {
            return false;
        }

        if (mb_strlen($t) < 40 || mb_strlen($t) > 130) {
            return false;
        }

        // One gap. Two gaps is a different exercise and a harder one.
        if (substr_count($t, $placeholder) !== 1) {
            return false;
        }

        // Subordinate clauses and asides make the item about parsing, not vocabulary.
        if (preg_match('/[;:\x{2013}\x{2014}"\x{201C}]|\s-\s/u', $t)) {
            return false;
        }

        if (substr_count($t, ',') > 1) {
            return false;
        }

        // A section letter and title that the page layout glued to the sentence
        // beneath it: "D Phrases and idioms for relationships Lily and I ______".
        if (preg_match('/^[A-Z]\s+[A-Z]/u', $t)) {
            return false;
        }

        // A gap at either end has context on one side only.
        if (str_starts_with($t, $placeholder) || preg_match('/'.preg_quote($placeholder, '/').'[.!?]?$/u', $t)) {
            return false;
        }

        return str_word_count(str_replace($placeholder, ' ', $t)) >= 6;
    }

    public function isMetalinguistic(?string $text): bool
    {
        return (bool) preg_match(self::METALINGUISTIC, trim((string) $text));
    }

    /**
     * More than one sentence in the string.
     *
     * A cloze stem must be a single sentence: the second half of a glued pair
     * is usually the truncated one, and the learner is asked to complete a
     * sentence whose subject belongs to a different thought.
     */
    public function isGlued(string $text): bool
    {
        $body = preg_replace('/[.!?"\x{2019}\x{201D}]+$/u', '', trim($text));

        // "Mr. Smith" is one sentence; the full stop belongs to the title.
        $body = preg_replace(self::ABBREVIATIONS, '', (string) $body);

        return (bool) preg_match('/[.!?]\s+[A-Z\x{2018}\x{201C}]/u', (string) $body);
    }

    /**
     * Word sequences that English does not produce.
     *
     * A two-column page comes out of the extractor with the columns interleaved,
     * and the splice lands mid-sentence: "She a system where some people have
     * the right to get likes us to take the initiative." It reads as prose and
     * passes every other check, but an article against a preposition, or a
     * pronoun against an article, is a seam - no writer produced it.
     */
    public function hasSpliceArtefact(string $text): bool
    {
        // "a at", "the of", "an and"
        if (preg_match('/\b(?:a|an|the)\s+(?:at|in|on|of|to|for|with|and|but|or|from|by)\b/iu', $text)) {
            return true;
        }

        // "She a system", "I the money"
        if (preg_match('/\b(?:he|she|it|they|we|I|you)\s+(?:a|an|the)\b/u', $text)) {
            return true;
        }

        // A speaker label from a printed dialogue, kept without its turn.
        if (preg_match('/^[A-Z]:\s/u', $text)) {
            return true;
        }

        return false;
    }

    /**
     * Does the sentence use this term as a whole word (or whole phrase)?
     *
     * Substring matching is not good enough: "art" is inside "start", and
     * blanking it there produces "st______". Hyphens and apostrophes count as
     * part of a word, so "flute-player" is not found inside "player".
     */
    public function containsTerm(string $sentence, string $term): bool
    {
        return preg_match($this->termPattern($term), $sentence) === 1;
    }

    /**
     * Replace every occurrence of the term with a blank.
     *
     * Every occurrence, not the first: the books repeat the headword inside its
     * own example, and blanking one copy leaves the answer printed in the stem.
     *
     * Returns null when the term does not appear as a whole word, so the caller
     * builds nothing rather than an item whose answer is not in the sentence.
     */
    public function blank(string $sentence, string $term, string $placeholder = '______'): ?string
    {
        if (! $this->containsTerm($sentence, $term)) {
            return null;
        }

        $blanked = preg_replace($this->termPattern($term), $placeholder, $sentence);

        if ($blanked === null || $blanked === $sentence) {
            return null;
        }

        // A stem that is now mostly blank teaches nothing: the learner needs
        // enough sentence left to work out what belongs in the gap.
        if (str_word_count(str_replace($placeholder, ' ', $blanked)) < self::MIN_CONTEXT_WORDS) {
            return null;
        }

        return $blanked;
    }

    /**
     * Whole-word/whole-phrase pattern for a term, tolerant of the spacing the
     * extraction left inside multi-word phrases.
     */
    private function termPattern(string $term): string
    {
        $parts = preg_split('/\s+/u', trim($term)) ?: [];
        $escaped = array_map(fn ($p) => preg_quote($p, '/'), $parts);

        return '/(?<![\w\x{2019}\'-])'.implode('\s+', $escaped).'(?![\w\x{2019}\'-])/iu';
    }
}
