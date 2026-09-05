<?php

namespace App\Services\Media;

use App\Models\CefrLevel;
use App\Models\Lesson;
use Illuminate\Support\Str;

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
    /** Never rendered into generated artwork. */
    private const NEGATIVE = 'text, letters, words, captions, watermark, signature, logo, '
        .'subtitles, numbers, distorted hands, extra limbs, deformed faces, gore, nudity, '
        .'brand names, low quality, blurry';

    public function lessonScene(Lesson $lesson, array $targetWords = []): array
    {
        $level = $lesson->cefr_level_id ? CefrLevel::find($lesson->cefr_level_id) : null;
        $unit = $lesson->unit;

        $words = collect($targetWords)->take(6)->implode(', ');
        $context = trim(($unit?->title ? $unit->title.' - ' : '').$lesson->title);

        $prompt = collect([
            'A clear, uncluttered illustrative scene for an English language lesson.',
            "Teaching context: {$context}.",
            $words !== '' ? "The scene should make these ideas visually obvious: {$words}." : null,
            $this->levelGuidance($level?->code),
            'Everyday setting, natural lighting, warm and neutral palette.',
            'Culturally neutral: no religious symbols, no national flags, no region-specific signage.',
            'Age-appropriate for a general adult audience.',
            'No writing of any kind anywhere in the image.',
            'Photographic realism, shallow depth of field, editorial quality.',
        ])->filter()->implode(' ');

        return [
            'prompt' => $prompt,
            'negative' => self::NEGATIVE,
            'aspect_ratio' => '16:9',
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

        $prompt = collect([
            $depictsSituation
                ? "A single clear image conveying the English word \"{$term}\"."
                : "A single clear subject illustrating the English word \"{$term}\".",
            $context ? "It is used like this: {$context}" : null,
            $framing,
            $this->levelGuidance($cefr),
            'Culturally neutral and age-appropriate.',
            'No text, letters or numbers in the image.',
        ])->filter()->implode(' ');

        return ['prompt' => $prompt, 'negative' => self::NEGATIVE, 'aspect_ratio' => '1:1'];
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
     * Visual complexity is tied to level: a beginner needs one obvious subject,
     * an advanced learner can read a busier scene with more inference in it.
     */
    private function levelGuidance(?string $cefr): ?string
    {
        return match ($cefr) {
            'Pre-A1', 'A1' => 'Very simple: one obvious subject, minimal background detail, nothing ambiguous.',
            'A2' => 'Simple and concrete: a small number of clearly separated elements.',
            'B1' => 'A realistic everyday situation with a few interacting elements.',
            'B2' => 'A richer situation with context the viewer can infer from.',
            'C1', 'C2' => 'A nuanced, layered scene that rewards close reading.',
            default => 'A clear, realistic everyday situation.',
        };
    }
}
