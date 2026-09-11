<?php

namespace App\Services\Media;

use App\Models\CefrLevel;
use App\Models\Lesson;

/**
 * Builds media prompts from teaching parameters rather than free text (spec 19).
 *
 * The rules below are not stylistic preferences - each one exists because
 * getting it wrong breaks the lesson. Text baked into an image cannot be
 * localised or read by a screen reader; culturally specific staging makes a
 * scene unusable for much of the audience; and a scene pitched above the
 * learner's level teaches nothing.
 */
class PromptBuilder
{
    /**
     * Compositions a single lesson image must never come back as.
     *
     * Public because a contact sheet is deliberately every one of these: nine
     * lessons in one grid. SheetComposer subtracts this list from the briefs'
     * own exclusions, so a sheet does not carry an instruction not to be a
     * sheet - which is the kind of contradiction a model resolves by ignoring
     * whichever half it likes.
     */
    public const MONTAGE_EXCLUSIONS = 'collage, grid of images, split screen, multiple panels, '
        .'photo montage, diptych, triptych, contact sheet, storyboard';

    /** Never rendered into generated artwork. */
    private const NEGATIVE = 'text, letters, words, captions, watermark, signature, logo, '
        .'subtitles, numbers, distorted hands, extra limbs, deformed faces, gore, nudity, '
        .'brand names, low quality, blurry, '.self::MONTAGE_EXCLUSIONS;

    public function lessonScene(Lesson $lesson, array $targetWords = []): array
    {
        $level = $lesson->cefr_level_id ? CefrLevel::find($lesson->cefr_level_id) : null;
        $unit = $lesson->unit;

        $words = collect($targetWords)->take(6)->implode(', ');
        $context = trim(($unit?->title ? $unit->title.' - ' : '').$lesson->title);

        /*
         * The scene itself, with none of the house style around it. Kept apart
         * because a sheet of nine scenes states the style once and then just
         * numbers these - see SheetComposer.
         */
        $scene = collect([
            "Teaching context: {$context}.",
            $this->ideas($unit?->title, $words),
            $this->levelGuidance($level?->code),
        ])->filter()->implode(' ');

        $prompt = collect([
            // "One single photograph" first and in those words. Asked for a
            // scene that makes several ideas obvious, these models reach for a
            // grid of small panels - which is the one composition a lesson card
            // cannot use: at card size every panel is too small to read, and
            // the picture stops being a situation the learner can talk about.
            'One single photograph: a clear, uncluttered scene for an English language lesson.',
            'A single continuous frame, not a collage and not divided into panels.',
            $scene,
            'Everyday setting, natural lighting, warm and neutral palette.',
            'Culturally neutral: no religious symbols, no national flags, no region-specific signage.',
            'Age-appropriate for a general adult audience.',
            'No writing of any kind anywhere in the image.',
            'Photographic realism, shallow depth of field, editorial quality.',
        ])->filter()->implode(' ');

        return [
            'prompt' => $prompt,
            'scene' => $scene,
            'negative' => self::NEGATIVE,
            /*
             * 4:3 because that is the box the client draws this in, and the
             * only one. A 16:9 scene is cropped to 4:3 on display, which threw
             * away a third of every image's width - off-centre, since the crop
             * is centred on the frame rather than on the subject - and paid for
             * the pixels anyway. It matters most on a contact sheet, where
             * cells are already small: nine 4:3 cells on a 4K sheet are about
             * 1080x810 each and all of it is used, where nine 16:9 cells leave
             * about 807x605 after the client's crop.
             */
            'aspect_ratio' => '4:3',
        ];
    }

    /**
     * A vocabulary card.
     *
     * Framing follows the word class, because the two need opposite treatments.
     * A concrete noun wants product photography - one object, plain ground, no
     * distractions. An adjective or verb has no object to photograph: "delighted"
     * on a plain background is nothing, while a person visibly delighted reads
     * instantly. Getting this wrong is the difference between a card that
     * teaches and a card that puzzles.
     *
     * @param  string|null  $context  A verbatim example sentence that disambiguates
     *                                the sense. Callers must pass only sentences that
     *                                survive their own quality check - a fragment here
     *                                produces a confidently wrong picture.
     */
    public function vocabularyImage(string $term, ?string $context, ?string $cefr = null, ?string $partOfSpeech = null): array
    {
        $depictsSituation = in_array($partOfSpeech, ['verb', 'adjective', 'adverb'], true);

        $framing = $depictsSituation
            ? 'Show a person or a moment that makes the meaning unmistakable. '
                .'Single clear situation, uncluttered background, natural light.'
            : 'One unambiguous subject, plain uncluttered background, centred composition. '
                .'Clean product-photography lighting.';

        /*
         * `scene` is only the part that tells this card apart from every other:
         * the word, and the sentence it is grounded in. The framing and the
         * level guidance are the same for every card on a contact sheet, and a
         * sheet states them once - sixteen repetitions of "plain uncluttered
         * background" crowd out the sixteen words the sheet is actually for.
         * The single-image prompt below is unchanged: same sentences, same
         * order.
         */
        $scene = collect([
            $depictsSituation
                ? "A single clear image conveying the English word \"{$term}\"."
                : "A single clear subject illustrating the English word \"{$term}\".",
            $context ? "It is used like this: {$context}" : null,
        ])->filter()->implode(' ');

        $prompt = collect([
            $scene,
            $framing,
            $this->levelGuidance($cefr),
            'Culturally neutral and age-appropriate.',
            'No text, letters or numbers in the image.',
        ])->filter()->implode(' ');

        return [
            'prompt' => $prompt,
            'scene' => $scene,
            'negative' => self::NEGATIVE,
            'aspect_ratio' => '1:1',
        ];
    }

    /**
     * Is this extracted string a real sentence, or an extraction artefact?
     *
     * The importer pulled examples out of running book text, and a good half of
     * what it caught are fragments: "or wound up / stressed out**", "tantamount
     * to admitting", "the second part, e.g. public \'transport". Those are
     * useless as image grounding and actively harmful - the model will illustrate
     * the fragment. This gate is deliberately strict; a card with no grounding is
     * skipped, which is a better outcome than a card grounded in noise.
     */
    public static function isUsableExample(?string $text): bool
    {
        $t = trim((string) $text);

        if (mb_strlen($t) < 15 || mb_strlen($t) > 220) {
            return false;
        }

        // Typographic debris left by the PDF extraction.
        if (preg_match('/[*=\[\]{}<>|]|\.{3}|e\.g\.|i\.e\./u', $t)) {
            return false;
        }

        // A sentence starts with a capital; a fragment usually starts mid-clause.
        if (! preg_match('/^[A-Z"\x{2018}\x{201C}]/u', $t)) {
            return false;
        }

        // ... and finishes.
        if (! preg_match('/[.!?"\x{2019}\x{201D}]$/u', $t)) {
            return false;
        }

        // Dictionary prose describing the word rather than using it.
        if (preg_match('/^(If you|When you|Someone who|Something that|A person who|Used to|This means)\b/iu', $t)) {
            return false;
        }

        // Alternatives separated by slashes are a lexis note, not a scene.
        if (substr_count($t, '/') > 1) {
            return false;
        }

        // Metalinguistic prose - the book talking ABOUT the word rather than
        // using it. Grammatically a fine sentence, useless as a picture.
        if (preg_match('/\b(means|refers to|is another way of saying|way of saying|is the opposite of|is more formal than)\b/iu', $t)) {
            return false;
        }

        return str_word_count($t) >= 4;
    }

    public function characterPortrait(string $name, string $persona, ?string $appearance = null): array
    {
        $prompt = collect([
            "Portrait of a recurring language-course character named {$name}.",
            'Character: '.rtrim($persona, '. ').'.',
            $appearance,
            'Neutral background, friendly natural expression, head and shoulders.',
            'Consistent, realistic, culturally neutral, age-appropriate.',
            'No text anywhere in the image.',
        ])->filter()->implode(' ');

        return ['prompt' => $prompt, 'negative' => self::NEGATIVE, 'aspect_ratio' => '1:1'];
    }

    /**
     * A dialogue played out as a short scene.
     *
     * The clip is deliberately written as behaviour, not speech. These models
     * move a mouth without forming English phonemes, so a clip that looks like
     * it is delivering the line teaches the wrong articulation - worse than a
     * still with accurate audio over it. What the video is for is the
     * situation: where these people are, what they are doing, how the exchange
     * feels. The words arrive as audio.
     */
    /**
     * A dialogue played out as a short scene.
     *
     * Deliberately written as behaviour, not speech, and deliberately NOT given
     * the script. Two reasons. These models move a mouth without forming
     * English phonemes, so a clip that looks like it is delivering the line
     * teaches the wrong articulation - worse than a still with accurate audio
     * over it. And the stored dialogue summary is the raw transcript, speaker
     * labels and numbers included; fed to a video model it comes back as a
     * scene trying to depict "B: She is 1.85 metres tall", which is not a
     * picture of anything. What the clip is for is the situation. The words
     * arrive as audio.
     *
     * @param  list<string>  $cast  names of the characters in the exchange, so a
     *                              recurring pair stays a recurring pair
     */
    public function dialogueVideo(string $setting, array $cast = [], ?string $topic = null, ?string $cefr = null, int $seconds = 5): array
    {
        $people = count($cast) >= 2
            ? "Two people, {$cast[0]} and {$cast[1]}, talking with each other."
            : 'Two people talking with each other.';

        $prompt = collect([
            "A short, calm observational scene in {$setting}, for an English language lesson.",
            $people,
            $topic ? "They are dealing with something to do with {$topic}." : null,
            $this->levelGuidance($cefr),
            'Natural body language and gesture; the exchange is readable from behaviour alone.',
            'Steady camera, gentle movement, unhurried pacing, clear framing.',
            'Do not emphasise the mouths and do not attempt close-up speech.',
            'Culturally neutral, age-appropriate, no on-screen text.',
        ])->filter()->implode(' ');

        return [
            'prompt' => $prompt,
            'negative' => self::NEGATIVE,
            'aspect_ratio' => '16:9',
            'duration_seconds' => $seconds,
        ];
    }

    /**
     * A lesson scene brought to life, animated from its own still.
     *
     * The motion is described rather than invented, and kept small on purpose:
     * a clip that reframes or restages the scene loses the very thing the still
     * was built to do, which is hold every target word in view at once.
     */
    public function lessonVideo(Lesson $lesson, string $motion, array $targetWords = [], int $seconds = 5): array
    {
        $unit = $lesson->unit;
        $context = trim(($unit?->title ? $unit->title.' - ' : '').$lesson->title);
        $words = collect($targetWords)->take(6)->implode(', ');

        $level = $lesson->cefr_level_id ? CefrLevel::find($lesson->cefr_level_id) : null;

        $prompt = collect([
            "Bring this scene gently to life for an English language lesson: {$context}.",
            $motion,
            $words !== '' ? "Keep these clearly visible throughout: {$words}." : null,
            $this->levelGuidance($level?->code),
            'Preserve the framing, the people and the setting exactly as they are.',
            'Subtle natural motion only. Steady camera, no cuts, no zoom, no reframing.',
            'Culturally neutral, age-appropriate, no on-screen text.',
        ])->filter()->implode(' ');

        return [
            'prompt' => $prompt,
            'negative' => self::NEGATIVE,
            'aspect_ratio' => '16:9',
            'duration_seconds' => $seconds,
        ];
    }

    /**
     * Bind a spec to the model that will actually render it.
     *
     * Exclusions ("no text, no watermark") only take effect if the model has
     * somewhere to put them. No image model in the current catalogue exposes a
     * negative-prompt parameter, so for those the exclusions have to be stated
     * inside the prompt itself or they simply do not happen - and a dropped
     * negative is invisible: the request succeeds and the artwork quietly comes
     * back with a watermark in it.
     *
     * Returns the spec with `prompt` final for this model and `negative` set
     * only when the model can genuinely receive it.
     */
    public function forModel(array $spec, string $model): array
    {
        $negative = $spec['negative'] ?? null;

        if ($negative === null || $negative === '') {
            return $spec;
        }

        if (in_array($model, config('ai.providers.higgsfield.negative_prompt_models', []), true)) {
            return $spec;
        }

        $spec['prompt'] = rtrim($spec['prompt'], ' ')
            .' Do not include any of the following: '.$negative.'.';
        $spec['negative'] = null;
        $spec['negative_folded'] = true;

        return $spec;
    }

    /**
     * Grammar words the book prints as labels, not as things to look at.
     *
     * They arrive in the target-word list because the extractor cannot tell a
     * heading from a headword, and a picture of "example, use" is nothing.
     */
    private const METALANGUAGE = [
        'example', 'examples', 'use', 'uses', 'used', 'note', 'notes', 'section',
        'verb', 'verbs', 'noun', 'nouns', 'adjective', 'adjectives', 'adverb', 'adverbs',
        'preposition', 'prepositions', 'expression', 'expressions', 'grammar', 'meaning',
        'singular', 'plural', 'countable', 'uncountable', 'formal', 'informal',
    ];

    /**
     * Words that are real English but cannot be photographed on their own.
     *
     * A scene can show somebody hesitating, but not the word "because".
     */
    private const FUNCTION_WORDS = [
        'and', 'but', 'or', 'because', 'so', 'if', 'than', 'also', 'only', 'like',
        'then', 'now', 'here', 'there', 'back', 'to', 'from', 'in', 'on', 'at', 'by',
        'of', 'with', 'for', 'the', 'a', 'an', 'is', 'are', 'was', 'were', 'do', 'does',
        'did', 'have', 'has', 'had', 'got', 'be', 'been', 'me', 'you', 'it', 'that',
        'this', 'what', 'how', 'when', 'where', 'why', 'not', 'very', 'well',
        // Contractions arrive split by the extractor: "ve got", "haven't got".
        'its', 'ive', 'ves', 've', 'll', 'dont', 'doesnt', 'didnt', 'havent',
        'hasnt', 'isnt', 'arent', 'wasnt', 'werent', 'cant', 'wont', 'some', 'any',
        // Particles, which arrive as headwords on their own ("around", "about")
        // and as the whole of a phrasal headword ("up and down", "out and
        // about"). A picture of "around" is a picture of the model's guess.
        'about', 'around', 'down', 'out', 'into', 'off', 'over', 'through',
    ];

    /**
     * What the picture should make obvious - or, sometimes, that it cannot.
     *
     * Target words come straight out of the book, and a good many of them are
     * not things: "example, use, and, but, or, because" for the conjunctions
     * unit, "got, It's got, ve got, haven't got" for the irregular verbs,
     * "Unit 32: T, ravelling" where a cross-reference was caught mid-word.
     * Handed to an image model those produce a confidently meaningless picture,
     * because there is nothing in them to photograph.
     *
     * What those lessons can usefully show is not the words but an ordinary
     * moment in which somebody would say them, which is what this falls back
     * to. The test is deliberately conservative: a lesson keeps its word list
     * unless almost nothing in it can be pictured, because a coarser filter
     * catches "adjectives describing appearance" and "words and expressions
     * about clothes", and both of those photograph perfectly well.
     */
    private function ideas(?string $unitTitle, string $words): ?string
    {
        $usable = $this->depictable($words);

        if ($usable !== '') {
            return "These ideas should be obvious within that one scene: {$usable}.";
        }

        // A verb's three principal parts as a unit title - "Have / had / had" -
        // names the verb the lesson is about, which is better than nothing.
        if (preg_match('/^\s*(\w+)\s*\/\s*\w+\s*\/\s*\w+/u', (string) $unitTitle, $m)) {
            return 'An ordinary everyday moment in which someone would naturally use the verb '
                ."\"{$m[1]}\" - the situation, not the word.";
        }

        return 'An ordinary everyday situation in which this language would be used, '
            .'shown as a moment rather than as words.';
    }

    /** The target words that are actually things a photograph could contain. */
    private function depictable(string $words): string
    {
        $kept = collect(explode(', ', $words))
            ->map(fn (string $w) => trim($w))
            ->reject(fn (string $w) => $w === '' || mb_strlen($w) < 3)
            // "Unit 32: T", "Unit 41" - a cross-reference the extractor caught,
            // sometimes mid-word, which is never part of the lesson's meaning.
            ->reject(fn (string $w) => (bool) preg_match('/\bUnit\s*\d+/iu', $w))
            ->reject(fn (string $w) => ! $this->carriesSomethingVisible($w));

        // Two is the threshold because one surviving word is usually the one
        // the extractor happened to catch whole, not the lesson's subject.
        return $kept->count() >= 2 ? $kept->implode(', ') : '';
    }

    /**
     * Is there anything here for a picture to be of?
     *
     * A vocabulary card is one image standing for one headword, so a headword
     * with nothing visible in it - "have to", "very well", "It's got" - buys a
     * picture of whatever the model guesses, attached to the word for ever. The
     * grounding sentence does not rescue it: "I'll meet you at around 6
     * o'clock" is a fine sentence and still leaves "around" unphotographable.
     *
     * Public because the decision belongs with the prompts - the same judgement
     * decides which of a lesson's target words are worth naming in its scene -
     * and the brief builder needs it before it queues anything to be paid for.
     */
    public function illustratable(string $headword): bool
    {
        return $this->carriesSomethingVisible($headword);
    }

    /**
     * Does this entry contain a single word a camera could point at?
     *
     * Tested word by word, because the entries are often phrases: "haven't got"
     * and "It's got" are both entirely function words and photograph as
     * nothing, while "ask someone the way" and "go swimming" each carry one
     * that does.
     */
    private function carriesSomethingVisible(string $entry): bool
    {
        $words = preg_split('/[^\p{L}]+/u', mb_strtolower($entry), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ($words as $word) {
            if (mb_strlen($word) < 3) {
                continue;
            }

            if (! in_array($word, self::FUNCTION_WORDS, true)
                && ! in_array($word, self::METALANGUAGE, true)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Visual complexity is tied to level: a beginner needs one obvious subject,
     * an advanced learner can read a busier scene with more inference in it.
     */
    private function levelGuidance(?string $cefr): ?string
    {
        return match ($cefr) {
            'Pre-A1', 'A1' => 'Very simple: one obvious subject, minimal background detail, nothing ambiguous.',
            'A2' => 'Simple and concrete: a few elements, clearly readable, all in the same room.',
            'B1' => 'A realistic everyday situation with a few interacting elements.',
            'B2' => 'A richer situation with context the viewer can infer from.',
            'C1', 'C2' => 'A nuanced, layered scene that rewards close reading.',
            default => 'A clear, realistic everyday situation.',
        };
    }
}
