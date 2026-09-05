<?php

namespace App\Services\Learning;

/**
 * The shape of a study session.
 *
 * The engine had a good answer to "what should this learner do?" and no answer
 * at all to "what is this learner doing?". Activities were interleaved by type
 * so that no two of a kind ran together, which is fine for variety and useless
 * as a structure: a session opened on whichever bucket was fullest, so a learner
 * with review debt was tested before anything had been taught, and a learner
 * asking where the vocabulary was being taught had no way to see that the
 * teaching was the third item down.
 *
 * So a session has parts now, in the order teaching actually works: start with
 * something known, meet the new material, use it under support, use it for real,
 * then close the loop on everything outstanding. Each part is named and carries
 * its purpose, because a learner who can see the shape of the hour can tell
 * whether it is going well.
 */
class SessionShape
{
    public const WARM_UP = 'warm_up';
    public const STUDY = 'study';
    public const PRACTISE = 'practise';
    public const USE = 'use';
    public const CONSOLIDATE = 'consolidate';

    /**
     * The phases in the order they run, with the share of the session each one
     * gets and the promise each one makes to the learner.
     *
     * @return array<string, array{title: string, purpose: string, share: float, min: int, max: int}>
     */
    public static function phases(): array
    {
        return [
            self::WARM_UP => [
                'title' => 'Warm-up',
                'purpose' => 'A couple of things you already know, to get going.',
                'share' => 0.10,
                'min' => 0,
                'max' => 3,
            ],
            self::STUDY => [
                'title' => 'Study',
                'purpose' => 'The lesson itself: the text, the picture and the new words.',
                'share' => 0.30,
                'min' => 1,
                'max' => 12,
            ],
            self::PRACTISE => [
                'title' => 'Practice',
                'purpose' => 'Use the words you have just met, while they are fresh.',
                'share' => 0.25,
                'min' => 0,
                'max' => 10,
            ],
            self::USE => [
                'title' => 'Use it',
                'purpose' => 'Listen, say it aloud, hold a conversation.',
                'share' => 0.15,
                'min' => 0,
                'max' => 6,
            ],
            self::CONSOLIDATE => [
                'title' => 'Consolidate',
                'purpose' => 'What is due today, and what you got wrong last time.',
                'share' => 0.20,
                'min' => 0,
                'max' => 20,
            ],
        ];
    }

    /** @return array<int, string> */
    public static function order(): array
    {
        return array_keys(self::phases());
    }

    /**
     * How many activities each phase gets for a session of this length.
     *
     * Roughly one activity a minute, then shifted by what this learner needs:
     * a review backlog pulls from study rather than from practice, because
     * meeting fewer new words is a smaller loss than meeting them and never
     * using them.
     *
     * @return array<string, int>
     */
    public static function slots(int $minutes, int $dueReviews, float $frustration = 0.0): array
    {
        $budget = max(4, $minutes);

        $shares = array_map(fn ($p) => $p['share'], self::phases());

        if ($dueReviews > 25) {
            $shares[self::CONSOLIDATE] += 0.12;
            $shares[self::STUDY] -= 0.12;
        } elseif ($dueReviews === 0) {
            $shares[self::CONSOLIDATE] -= 0.10;
            $shares[self::STUDY] += 0.06;
            $shares[self::PRACTISE] += 0.04;
        }

        // Someone who has been getting things wrong needs the ground they
        // already hold, not more new ground.
        if ($frustration > 0.6) {
            $shares[self::STUDY] -= 0.10;
            $shares[self::WARM_UP] += 0.04;
            $shares[self::CONSOLIDATE] += 0.06;
        }

        $slots = [];
        foreach (self::phases() as $key => $phase) {
            $share = max(0.0, $shares[$key]);
            $slots[$key] = max(
                $phase['min'],
                min($phase['max'], (int) round($budget * $share)),
            );
        }

        return $slots;
    }

    public static function title(string $phase): string
    {
        return self::phases()[$phase]['title'] ?? ucfirst(str_replace('_', ' ', $phase));
    }

    public static function purpose(string $phase): string
    {
        return self::phases()[$phase]['purpose'] ?? '';
    }
}
