<?php

namespace Database\Seeders;

use App\Models\CefrLevel;
use App\Models\Language;
use App\Models\MinimalPair;
use App\Models\Phoneme;
use App\Models\PronunciationItem;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * The General American English phoneme inventory, plus the minimal pairs that
 * force the contrasts learners most often collapse.
 *
 * IPA is what the learner sees; ARPABET is what forced aligners emit, so both
 * are stored on every row and either one resolves to the same phoneme. The
 * articulation hints are the actual teaching content: "put your tongue between
 * your teeth" is what fixes /θ/, not a score.
 */
class PhonemeSeeder extends Seeder
{
    public function run(): void
    {
        $language = Language::firstOrCreate(
            ['code' => 'en'],
            ['name' => 'English', 'native_name' => 'English', 'direction' => 'ltr',
             'is_learnable' => true, 'is_interface' => true],
        );

        $phonemes = $this->seedPhonemes($language->id);
        $items = $this->seedItems($language->id, $phonemes);
        $this->seedMinimalPairs($language->id, $phonemes, $items);
    }

    /** @return array<string,int> IPA symbol => phoneme id */
    private function seedPhonemes(int $languageId): array
    {
        $ids = [];
        foreach ($this->inventory() as $row) {
            $phoneme = Phoneme::updateOrCreate(
                ['language_id' => $languageId, 'ipa' => $row['ipa']],
                [
                    'arpabet' => $row['arpabet'],
                    'type' => $row['type'],
                    'features' => $row['features'],
                    'articulation_hint' => $row['hint'],
                ],
            );
            $ids[$row['ipa']] = $phoneme->id;
        }

        return $ids;
    }

    /**
     * @param  array<string,int>  $phonemes
     * @return array<string,int> word => pronunciation item id
     */
    private function seedItems(int $languageId, array $phonemes): array
    {
        $cefrId = CefrLevel::where('code', 'A1')->value('id');
        $ids = [];

        foreach ($this->words() as $text => $sequence) {
            $item = PronunciationItem::updateOrCreate(
                ['language_id' => $languageId, 'text' => $text, 'accent' => 'GA'],
                ['ipa' => '/'.implode('', $sequence).'/', 'cefr_level_id' => $cefrId],
            );
            $ids[$text] = $item->id;

            // The ordered sequence is what lets the aligner score each sound
            // separately instead of marking the whole word right or wrong.
            DB::table('pronunciation_item_phonemes')->where('pronunciation_item_id', $item->id)->delete();
            $rows = [];
            $stressAssigned = false;
            foreach ($sequence as $position => $symbol) {
                if (! isset($phonemes[$symbol])) {
                    continue;
                }
                // Every word here is stressed on its first syllable, so the first
                // vowel in the sequence is the stressed one.
                $stressed = ! $stressAssigned && $this->isVowel($symbol);
                $stressAssigned = $stressAssigned || $stressed;
                $rows[] = [
                    'pronunciation_item_id' => $item->id,
                    'phoneme_id' => $phonemes[$symbol],
                    'position' => $position,
                    'is_stressed' => $stressed,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
            if ($rows) {
                DB::table('pronunciation_item_phonemes')->insert($rows);
            }
        }

        return $ids;
    }

    /**
     * @param  array<string,int>  $phonemes
     * @param  array<string,int>  $items
     */
    private function seedMinimalPairs(int $languageId, array $phonemes, array $items): void
    {
        $cefrId = CefrLevel::where('code', 'A2')->value('id');

        foreach ($this->pairs() as [$ipaA, $ipaB, $wordA, $wordB]) {
            if (! isset($phonemes[$ipaA], $phonemes[$ipaB], $items[$wordA], $items[$wordB])) {
                continue;
            }
            MinimalPair::updateOrCreate(
                ['item_a_id' => $items[$wordA], 'item_b_id' => $items[$wordB]],
                [
                    'language_id' => $languageId,
                    'phoneme_a_id' => $phonemes[$ipaA],
                    'phoneme_b_id' => $phonemes[$ipaB],
                    'cefr_level_id' => $cefrId,
                ],
            );
        }
    }

    private function isVowel(string $symbol): bool
    {
        static $vowels = null;
        $vowels ??= array_column(
            array_filter($this->inventory(), fn ($r) => $r['type'] !== 'consonant'),
            'ipa',
        );

        return in_array($symbol, $vowels, true);
    }

    /**
     * 24 consonants, 11 monophthongs and 5 diphthongs.
     *
     * @return array<int,array{ipa:string,arpabet:string,type:string,features:array,hint:string}>
     */
    private function inventory(): array
    {
        $c = fn (string $ipa, string $arpa, string $place, string $manner, bool $voiced, string $hint) => [
            'ipa' => $ipa, 'arpabet' => $arpa, 'type' => 'consonant',
            'features' => ['place' => $place, 'manner' => $manner, 'voicing' => $voiced ? 'voiced' : 'voiceless'],
            'hint' => $hint,
        ];
        $v = fn (string $ipa, string $arpa, string $height, string $back, bool $round, bool $tense, string $hint) => [
            'ipa' => $ipa, 'arpabet' => $arpa, 'type' => 'vowel',
            'features' => ['height' => $height, 'backness' => $back,
                'rounding' => $round ? 'rounded' : 'unrounded', 'tenseness' => $tense ? 'tense' : 'lax'],
            'hint' => $hint,
        ];
        $d = fn (string $ipa, string $arpa, string $from, string $to, string $hint) => [
            'ipa' => $ipa, 'arpabet' => $arpa, 'type' => 'diphthong',
            'features' => ['glide_from' => $from, 'glide_to' => $to],
            'hint' => $hint,
        ];

        return [
            // --- plosives
            $c('p', 'P', 'bilabial', 'plosive', false, 'Close both lips, build pressure, release with a puff of air at the start of a word: "pen".'),
            $c('b', 'B', 'bilabial', 'plosive', true, 'Same lip closure as /p/ but with the voice on and no puff of air: "bad".'),
            $c('t', 'T', 'alveolar', 'plosive', false, 'Tongue tip on the ridge behind the top teeth, release sharply: "top".'),
            $c('d', 'D', 'alveolar', 'plosive', true, 'Same tongue position as /t/, with the voice on: "dog".'),
            $c('k', 'K', 'velar', 'plosive', false, 'Back of the tongue against the soft palate, release: "cat".'),
            $c('g', 'G', 'velar', 'plosive', true, 'Same as /k/ with the voice on: "go".'),

            // --- affricates
            $c('tʃ', 'CH', 'postalveolar', 'affricate', false, 'Start as /t/, release into /ʃ/ in one movement: "chair".'),
            $c('dʒ', 'JH', 'postalveolar', 'affricate', true, 'Start as /d/, release into /ʒ/ with the voice on: "job".'),

            // --- fricatives
            $c('f', 'F', 'labiodental', 'fricative', false, 'Top teeth on the bottom lip, blow air through: "fish".'),
            $c('v', 'V', 'labiodental', 'fricative', true, 'Top teeth on the bottom lip with the voice on - not both lips, which turns it into /w/: "very".'),
            $c('θ', 'TH', 'dental', 'fricative', false, 'Tongue tip lightly between the teeth, blow air, no voice: "think". Keeping the tongue behind the teeth gives /s/ or /t/ instead.'),
            $c('ð', 'DH', 'dental', 'fricative', true, 'Tongue tip between the teeth with the voice on: "this". Behind the teeth it becomes /d/ or /z/.'),
            $c('s', 'S', 'alveolar', 'fricative', false, 'Tongue near the ridge behind the top teeth, narrow groove, hiss: "see".'),
            $c('z', 'Z', 'alveolar', 'fricative', true, 'Same as /s/ with the voice on - you should feel the throat buzz: "zoo".'),
            $c('ʃ', 'SH', 'postalveolar', 'fricative', false, 'Tongue pulled slightly back from /s/, lips a little rounded: "she".'),
            $c('ʒ', 'ZH', 'postalveolar', 'fricative', true, 'Same as /ʃ/ with the voice on; usually in the middle of a word: "measure".'),
            $c('h', 'HH', 'glottal', 'fricative', false, 'Simple breath out with the mouth already in position for the next vowel: "hat".'),

            // --- nasals
            $c('m', 'M', 'bilabial', 'nasal', true, 'Lips closed, air out through the nose: "man".'),
            $c('n', 'N', 'alveolar', 'nasal', true, 'Tongue tip on the ridge behind the top teeth, air through the nose: "no".'),
            $c('ŋ', 'NG', 'velar', 'nasal', true, 'Back of the tongue against the soft palate, air through the nose, and no /g/ after it: "sing".'),

            // --- approximants
            $c('l', 'L', 'alveolar', 'lateral approximant', true, 'Tongue tip on the ridge, air flows over the sides of the tongue: "look".'),
            $c('r', 'R', 'alveolar', 'approximant', true, 'Tongue curled back or bunched, tip touching nothing at all: "red". Any tap or trill sounds like a different language.'),
            $c('w', 'W', 'labial-velar', 'approximant', true, 'Round both lips and glide - the teeth never touch the lip, which is what separates it from /v/: "we".'),
            $c('j', 'Y', 'palatal', 'approximant', true, 'Tongue high and forward, glide straight into the vowel: "yes".'),

            // --- monophthongs
            $v('i', 'IY', 'close', 'front', false, true, 'Long and tense, lips spread as in a smile: "sheep".'),
            $v('ɪ', 'IH', 'near-close', 'front', false, false, 'Short and relaxed, jaw slightly lower than /i/: "ship".'),
            $v('ɛ', 'EH', 'open-mid', 'front', false, false, 'Mid-front, mouth moderately open: "bed".'),
            $v('æ', 'AE', 'near-open', 'front', false, false, 'Jaw low, tongue forward, quite long: "cat".'),
            $v('ɑ', 'AA', 'open', 'back', false, true, 'Mouth wide open, tongue low and back: "father".'),
            $v('ɔ', 'AO', 'open-mid', 'back', true, true, 'Back of the tongue low, lips slightly rounded: "thought".'),
            $v('ʊ', 'UH', 'near-close', 'back', true, false, 'Short, relaxed, lips only lightly rounded: "book".'),
            $v('u', 'UW', 'close', 'back', true, true, 'Long, tongue high and back, lips firmly rounded: "food".'),
            $v('ʌ', 'AH', 'open-mid', 'central', false, false, 'Central and short, mouth relaxed: "cup".'),
            $v('ə', 'AX', 'mid', 'central', false, false, 'The weak vowel of unstressed syllables - the shortest, most relaxed sound in English: "about".'),
            $v('ɝ', 'ER', 'open-mid', 'central', false, true, 'Stressed r-coloured vowel; the tongue is already in the /r/ shape: "bird".'),

            // --- diphthongs
            $d('eɪ', 'EY', 'ɛ', 'ɪ', 'Start mid-front and glide up towards /ɪ/ in one smooth movement: "day".'),
            $d('aɪ', 'AY', 'a', 'ɪ', 'Start with the mouth open and glide to /ɪ/: "my".'),
            $d('ɔɪ', 'OY', 'ɔ', 'ɪ', 'Start rounded and back, glide forward to /ɪ/: "boy".'),
            $d('aʊ', 'AW', 'a', 'ʊ', 'Start open, glide back and round the lips: "now".'),
            $d('oʊ', 'OW', 'o', 'ʊ', 'Start mid-back with rounded lips and glide to /ʊ/: "go".'),
        ];
    }

    /**
     * Words used by the minimal pairs, each with its ordered phoneme sequence.
     *
     * @return array<string,array<int,string>>
     */
    private function words(): array
    {
        return [
            'think' => ['θ', 'ɪ', 'ŋ', 'k'],
            'sink' => ['s', 'ɪ', 'ŋ', 'k'],
            'thin' => ['θ', 'ɪ', 'n'],
            'tin' => ['t', 'ɪ', 'n'],
            'they' => ['ð', 'eɪ'],
            'day' => ['d', 'eɪ'],
            'breathe' => ['b', 'r', 'i', 'ð'],
            'breeze' => ['b', 'r', 'i', 'z'],
            'west' => ['w', 'ɛ', 's', 't'],
            'vest' => ['v', 'ɛ', 's', 't'],
            'vine' => ['v', 'aɪ', 'n'],
            'fine' => ['f', 'aɪ', 'n'],
            'van' => ['v', 'æ', 'n'],
            'ban' => ['b', 'æ', 'n'],
            'pat' => ['p', 'æ', 't'],
            'bat' => ['b', 'æ', 't'],
            'light' => ['l', 'aɪ', 't'],
            'right' => ['r', 'aɪ', 't'],
            'night' => ['n', 'aɪ', 't'],
            'white' => ['w', 'aɪ', 't'],
            'ship' => ['ʃ', 'ɪ', 'p'],
            'sheep' => ['ʃ', 'i', 'p'],
            'bad' => ['b', 'æ', 'd'],
            'bed' => ['b', 'ɛ', 'd'],
            'full' => ['f', 'ʊ', 'l'],
            'fool' => ['f', 'u', 'l'],
            'cot' => ['k', 'ɑ', 't'],
            'cut' => ['k', 'ʌ', 't'],
            'shoe' => ['ʃ', 'u'],
            'chew' => ['tʃ', 'u'],
            'cheap' => ['tʃ', 'i', 'p'],
            'jeep' => ['dʒ', 'i', 'p'],
            'bus' => ['b', 'ʌ', 's'],
            'buzz' => ['b', 'ʌ', 'z'],
            'sea' => ['s', 'i'],
            'she' => ['ʃ', 'i'],
            'sing' => ['s', 'ɪ', 'ŋ'],
            'sin' => ['s', 'ɪ', 'n'],
            'bought' => ['b', 'ɔ', 't'],
            'boat' => ['b', 'oʊ', 't'],
            'late' => ['l', 'eɪ', 't'],
            'let' => ['l', 'ɛ', 't'],
            'yet' => ['j', 'ɛ', 't'],
            'jet' => ['dʒ', 'ɛ', 't'],
            'bird' => ['b', 'ɝ', 'd'],
            'bud' => ['b', 'ʌ', 'd'],
            'town' => ['t', 'aʊ', 'n'],
            'tone' => ['t', 'oʊ', 'n'],
            'boy' => ['b', 'ɔɪ'],
            'buy' => ['b', 'aɪ'],
            'goat' => ['g', 'oʊ', 't'],
            'coat' => ['k', 'oʊ', 't'],
            'map' => ['m', 'æ', 'p'],
            'nap' => ['n', 'æ', 'p'],
            'lesion' => ['l', 'i', 'ʒ', 'ə', 'n'],
            'legion' => ['l', 'i', 'dʒ', 'ə', 'n'],
            'fin' => ['f', 'ɪ', 'n'],
            'hat' => ['h', 'æ', 't'],
            'cat' => ['k', 'æ', 't'],
        ];
    }

    /**
     * The contrasts learners most often collapse, including the ones the
     * pronunciation profile is expected to surface: /θ/-/s/, /ð/-/d/, /w/-/v/,
     * /l/-/r/, /ɪ/-/i/.
     *
     * @return array<int,array{0:string,1:string,2:string,3:string}>
     */
    private function pairs(): array
    {
        return [
            ['θ', 's', 'think', 'sink'],
            ['θ', 't', 'thin', 'tin'],
            ['ð', 'd', 'they', 'day'],
            ['ð', 'z', 'breathe', 'breeze'],
            ['w', 'v', 'west', 'vest'],
            ['v', 'f', 'vine', 'fine'],
            ['v', 'b', 'van', 'ban'],
            ['p', 'b', 'pat', 'bat'],
            ['l', 'r', 'light', 'right'],
            ['n', 'l', 'night', 'light'],
            ['r', 'w', 'right', 'white'],
            ['ɪ', 'i', 'ship', 'sheep'],
            ['æ', 'ɛ', 'bad', 'bed'],
            ['ʊ', 'u', 'full', 'fool'],
            ['ɑ', 'ʌ', 'cot', 'cut'],
            ['ʃ', 'tʃ', 'shoe', 'chew'],
            ['tʃ', 'dʒ', 'cheap', 'jeep'],
            ['s', 'z', 'bus', 'buzz'],
            ['s', 'ʃ', 'sea', 'she'],
            ['ŋ', 'n', 'sing', 'sin'],
            ['ɔ', 'oʊ', 'bought', 'boat'],
            ['eɪ', 'ɛ', 'late', 'let'],
            ['j', 'dʒ', 'yet', 'jet'],
            ['ɝ', 'ʌ', 'bird', 'bud'],
            ['aʊ', 'oʊ', 'town', 'tone'],
            ['ɔɪ', 'aɪ', 'boy', 'buy'],
            ['g', 'k', 'goat', 'coat'],
            ['d', 't', 'bad', 'bat'],
            ['m', 'n', 'map', 'nap'],
            ['ʒ', 'dʒ', 'lesion', 'legion'],
            ['f', 'θ', 'fin', 'thin'],
            ['h', 'k', 'hat', 'cat'],
        ];
    }
}
