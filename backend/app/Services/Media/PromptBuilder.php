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

    public function vocabularyImage(string $term, ?string $gloss, ?string $cefr = null): array
    {
        $prompt = collect([
            "A single clear subject illustrating the English word \"{$term}\".",
            $gloss ? "Meaning: {$gloss}." : null,
            'One unambiguous subject, plain uncluttered background, centred composition.',
            $this->levelGuidance($cefr),
            'Culturally neutral and age-appropriate.',
            'No text, letters or numbers in the image.',
            'Clean product-photography lighting.',
        ])->filter()->implode(' ');

        return ['prompt' => $prompt, 'negative' => self::NEGATIVE, 'aspect_ratio' => '1:1'];
    }

    public function characterPortrait(string $name, string $persona, ?string $appearance = null): array
    {
        $prompt = collect([
            "Portrait of a recurring language-course character named {$name}.",
            "Character: {$persona}.",
            $appearance,
            'Neutral background, friendly natural expression, head and shoulders.',
            'Consistent, realistic, culturally neutral, age-appropriate.',
            'No text anywhere in the image.',
        ])->filter()->implode(' ');

        return ['prompt' => $prompt, 'negative' => self::NEGATIVE, 'aspect_ratio' => '1:1'];
    }

    public function dialogueVideo(string $setting, string $summary, ?string $cefr = null, int $seconds = 8): array
    {
        $prompt = collect([
            "A short, calm scene set in a {$setting} for an English language lesson.",
            $summary,
            $this->levelGuidance($cefr),
            'Natural pacing, steady camera, clear framing of the people speaking.',
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
