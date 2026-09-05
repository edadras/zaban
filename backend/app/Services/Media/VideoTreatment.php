<?php

namespace App\Services\Media;

/**
 * Decides whether a lesson earns a video, and what the camera should do.
 *
 * Not every lesson does. Roughly two hundred of them teach the language itself
 * rather than a situation - prefixes, word-building, register, punctuation -
 * and there is no footage of a suffix. Filming those would spend real quota on
 * clips that cannot teach anything the still does not already teach better.
 *
 * The rest are ranked by how much they gain from motion, because the render
 * quota is finite and the manifest should make the best clips first:
 *
 *   dialogue  - a real exchange between two people. The strongest case: the
 *               situation is the lesson.
 *   scenario  - a lesson that is itself a situation (checking into a hotel,
 *               ordering food, asking directions).
 *   action    - topical vocabulary about things that happen: cooking, sport,
 *               weather, travel, work. Motion carries meaning here.
 *   ambient   - topical vocabulary about things that simply are: furniture,
 *               food, parts of the body. A still already does this well, so
 *               these get the gentlest treatment and go last.
 */
class VideoTreatment
{
    public const TIER_DIALOGUE = 'dialogue';

    public const TIER_SCENARIO = 'scenario';

    public const TIER_ACTION = 'action';

    public const TIER_AMBIENT = 'ambient';

    public const TIER_NONE = 'none';

    /** Lessons about language rather than about the world. */
    private const METALINGUISTIC = '(Prefix|Suffix|Word.building|Word.blending|Abbreviat|Acronym|Spelling|'
        .'Punctuation|Grammar|Nouns?\b|Adjectiv|Adverb|Verb pattern|Idiom|Collocation|Phrasal|Synonym|'
        .'Antonym|Opposite|Confused|Formal|Informal|Register|Style|Connecting|Linking|Discourse|'
        .'Pronunciation|Stress|Intonation|Saying|Countable|Uncountable|Singular|Plural|Tense|Modal|'
        .'Preposition|Conjunction|Article|Pronoun|Compound|Affix|Etymolog|Dictionary|'
        .'Vocabulary learning|Keeping a|Organising|Word formation|Words? and )';

    /** A situation someone is in. */
    private const SCENARIO = '(Conversation|Dialogue|At the|At a|In a|In the|On the|Asking|Ordering|Booking|'
        .'Buying|Selling|Making arrangements|Talking|Greeting|Meeting|Phone|Telephone|Shopping|Hotel|'
        .'Restaurant|Cafe|Caf\x{00e9}|Doctor|Hospital|Interview|Directions|Airport|Station|Bank|'
        .'Post office|Complain|Apolog|Invit|Offer|Request|Advice|Small talk|Socialising|Visit)';

    /** Vocabulary about things that happen rather than things that sit still. */
    private const ACTION = '(Cook|Food|Eat|Drink|Meal|Sport|Exercis|Game|Play|Travel|Journey|Transport|'
        .'Drive|Driving|Traffic|Flight|Train|Weather|Climate|Storm|Rain|Work|Job|Career|Office|Business|'
        .'Study|School|Education|Class|Shop|Market|Money|Accident|Crime|Police|Health|Illness|Treatment|'
        .'Music|Film|Cinema|Theatre|Dance|Holiday|Party|Celebrat|Festival|Wedding|Movement|Walk|Run|'
        .'Build|Repair|Clean|Garden|Farm|Animal|Child|Family life|Daily|Routine|Morning|Night)';

    /**
     * @return array{tier:string, motion:string, seconds:int}|null null when the
     *                                                            lesson should have no video at all
     */
    public function forLesson(string $title, ?string $unitTitle = null): ?array
    {
        $haystack = trim(($unitTitle ?? '').' '.$title);

        if (preg_match('/'.self::METALINGUISTIC.'/iu', $haystack)) {
            return null;
        }

        if (preg_match('/'.self::SCENARIO.'/iu', $haystack)) {
            return [
                'tier' => self::TIER_SCENARIO,
                'motion' => 'The people in the scene continue what they are doing: small gestures, '
                    .'a glance between them, natural weight shifts.',
                'seconds' => 5,
            ];
        }

        if (preg_match('/'.self::ACTION.'/iu', $haystack)) {
            return [
                'tier' => self::TIER_ACTION,
                'motion' => 'The activity in the scene continues at its own pace, so the action the '
                    .'words describe is visible happening.',
                'seconds' => 5,
            ];
        }

        return [
            'tier' => self::TIER_AMBIENT,
            // Nothing should move much here. The still is already doing the
            // teaching; the clip only stops it feeling dead.
            'motion' => 'Almost nothing moves: a slow drift of light, a curtain, steam, a faint breeze.',
            'seconds' => 5,
        ];
    }

    /**
     * A physical place to film in, inferred from what the lesson is about.
     *
     * Dialogue rows carry their lesson's title as their "setting", and a lesson
     * title is usually not a place - "Expressions", "Nouns and adjectives".
     * Handing that straight to a video model produces a scene set in nothing.
     * This maps the subject onto somewhere a camera could actually stand.
     */
    public function settingFor(string $title, ?int $seed = null): string
    {
        $places = [
            'hotel' => 'a small hotel reception',
            'room' => 'a small hotel reception',
            'restaurant' => 'a modest restaurant',
            'eating' => 'a modest restaurant',
            'food' => 'a bright kitchen',
            'cook' => 'a bright kitchen',
            'meal' => 'a family kitchen table',
            'shop' => 'a small shop',
            'shopping' => 'a small shop',
            'market' => 'a covered market stall',
            'money' => 'a bank counter',
            'bank' => 'a bank counter',
            'health' => 'a small clinic consulting room',
            'illness' => 'a small clinic consulting room',
            'doctor' => 'a small clinic consulting room',
            'hospital' => 'a quiet hospital corridor',
            'body' => 'a small clinic consulting room',
            'work' => 'an ordinary open-plan office',
            'job' => 'an ordinary open-plan office',
            'office' => 'an ordinary open-plan office',
            'interview' => 'a plain meeting room',
            'career' => 'a plain meeting room',
            'business' => 'a plain meeting room',
            'study' => 'a bright classroom',
            'school' => 'a bright classroom',
            'education' => 'a bright classroom',
            'exam' => 'a quiet exam hall',
            'travel' => 'a railway station platform',
            'transport' => 'a railway station platform',
            'train' => 'a railway station platform',
            'airport' => 'an airport check-in hall',
            'flight' => 'an airport check-in hall',
            'road' => 'a residential street',
            'direction' => 'a street corner',
            'traffic' => 'a residential street',
            'weather' => 'a doorway looking out at the sky',
            'family' => 'a comfortable living room',
            'home' => 'a comfortable living room',
            'house' => 'a comfortable living room',
            'friend' => 'a quiet cafe table',
            'relationship' => 'a quiet cafe table',
            'phone' => 'a hallway beside a telephone',
            'sport' => 'the edge of a sports pitch',
            'exercise' => 'a small gym',
            'music' => 'a living room with an instrument',
            'film' => 'a cinema foyer',
            'holiday' => 'a sunlit promenade',
            'party' => 'a living room set for guests',
            'garden' => 'a small back garden',
            'farm' => 'a farmyard',
            'animal' => 'a farmyard',
            'emotion' => 'a comfortable living room',
            'feeling' => 'a comfortable living room',
            'regret' => 'a comfortable living room',
            'reminiscen' => 'a comfortable living room',
            'finance' => 'a kitchen table covered in paperwork',
            'saving' => 'a kitchen table covered in paperwork',
            'pension' => 'a kitchen table covered in paperwork',
            'difficult' => 'a kitchen table covered in paperwork',
            'news' => 'a living room with a television on',
            'media' => 'a living room with a television on',
            'technology' => 'a desk with a laptop open',
            'computer' => 'a desk with a laptop open',
            'internet' => 'a desk with a laptop open',
        ];

        $haystack = mb_strtolower($title);

        foreach ($places as $keyword => $place) {
            if (str_contains($haystack, $keyword)) {
                return $place;
            }
        }

        /*
         * Much of the corpus teaches functional language - expressing regret,
         * modality, "do / did / done" - which has no setting of its own. That
         * is not a parse failure; those conversations really do happen
         * anywhere. But "an everyday setting" gives a camera nothing to point
         * at, so the fallback is a concrete neutral place where two people
         * plausibly sit and talk.
         *
         * Two thirds of the dialogues land here, so one fixed fallback would
         * put the same cafe behind fifty clips. The choice is rotated by a
         * caller-supplied seed - deterministic, so a rebuild does not reshuffle
         * scenes that have already been rendered.
         */
        $neutral = [
            'a quiet cafe table by a window',
            'a comfortable living room',
            'a kitchen with morning light',
            'a bench in a small park',
            'a hallway just inside a front door',
            'a quiet corner of a public library',
            'a shared office kitchen',
        ];

        return $neutral[($seed ?? 0) % count($neutral)];
    }

    public function priorityFor(string $tier): int
    {
        return match ($tier) {
            self::TIER_DIALOGUE => 400,
            self::TIER_SCENARIO => 500,
            self::TIER_ACTION => 600,
            default => 700,
        };
    }
}
