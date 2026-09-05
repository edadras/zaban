<?php

namespace App\Services\Exam;

/**
 * Every number this engine produces is an estimate, and must be shown as one.
 *
 * A learner who mistakes a practice band for a real one books the wrong test
 * date and loses the fee, so the disclaimer travels with the score through the
 * services, the resources and the stored evidence rather than being left to the
 * client to remember.
 */
final class ExamEstimate
{
    public const AI_DISCLAIMER = 'Estimated score. Produced by an automated model from your practice responses. '
        .'It is not an official result, has no relationship to any examining board, and cannot be used for '
        .'admission, visa or employment purposes. Human examiners routinely award a different score.';

    public const DETERMINISTIC_DISCLAIMER = 'Unofficial practice score, marked automatically against the answer key. '
        .'It reflects only the questions in this practice set and is not an official result.';

    public const PRIOR_DISCLAIMER = 'Includes sections you did not attempt, projected from your current course level. '
        .'Those sections were not measured in this attempt.';

    /**
     * The envelope every API resource carrying a score must include.
     *
     * @param  string[]  $priorSections  section codes filled from the curriculum rather than measured
     * @return array<string, mixed>
     */
    public static function label(bool $aiEstimated, array $priorSections = []): array
    {
        $notes = [$aiEstimated ? self::AI_DISCLAIMER : self::DETERMINISTIC_DISCLAIMER];
        if ($priorSections) {
            $notes[] = self::PRIOR_DISCLAIMER;
        }

        return [
            'is_estimate' => true,
            'is_official' => false,
            'is_ai_estimated' => $aiEstimated,
            'projected_sections' => array_values($priorSections),
            'disclaimer' => implode(' ', $notes),
        ];
    }
}
