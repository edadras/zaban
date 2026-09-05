<?php

namespace Database\Seeders;

use App\Models\Character;
use Illuminate\Database\Seeder;

/**
 * The recurring cast.
 *
 * The source books are vocabulary references, not dialogue-driven coursebooks -
 * they have no cast to extract. So the cast is designed here instead, and that
 * is the point of this file existing at all: a fixed, named, describable set of
 * people means the artwork for 1,100 lessons can show the same faces rather than
 * a thousand strangers. Recognising a character across units is what turns a pile
 * of vocabulary pages into a course someone can live inside.
 *
 * Every field below is load-bearing for reproducibility:
 *
 *  - `appearance_prompt` is the physical description fed to the image model on
 *    every single generation. It is deliberately specific about the things a
 *    model drifts on - age, hair, build, habitual clothing - and deliberately
 *    silent about pose and setting, which change per scene.
 *  - `soul_id` is filled in later, once a portrait has been trained into a
 *    provider-side identity. Until then the appearance text is what holds the
 *    character together, which is weaker but costs nothing.
 *  - `accent` drives voice selection for spoken lines, so a character sounds
 *    the same as well as looking the same.
 *
 * Casting notes: the roles cover the situations the books actually teach -
 * travel, shops, health, work, study, home. The cast is deliberately varied in
 * age and appearance and deliberately free of national, religious or political
 * markers, per the content rules in PromptBuilder.
 */
class CastSeeder extends Seeder
{
    /**
     * Shared across every portrait so the cast looks like one photographic set
     * rather than fifteen unrelated stock photos.
     */
    private const HOUSE_LOOK = 'Natural available light, soft neutral background, '
        .'documentary portrait style, no studio gloss.';

    public function run(): void
    {
        foreach ($this->cast() as $c) {
            Character::updateOrCreate(
                ['slug' => $c['slug']],
                [
                    'name' => $c['name'],
                    'persona' => $c['persona'],
                    'accent' => $c['accent'],
                    'appearance_prompt' => $c['appearance'].' '.self::HOUSE_LOOK,
                ],
            );
        }
    }

    /**
     * @return list<array{slug:string,name:string,accent:string,persona:string,appearance:string}>
     */
    private function cast(): array
    {
        return [
            [
                'slug' => 'maya',
                'name' => 'Maya',
                'accent' => 'en-GB',
                'persona' => 'The learner\'s counterpart: a woman in her early thirties who is '
                    .'herself studying and travelling, so she asks the questions a learner would ask.',
                'appearance' => 'A woman in her early thirties, shoulder-length dark brown wavy hair, '
                    .'warm olive skin, brown eyes, slim build, usually in a plain olive-green jumper.',
            ],
            [
                'slug' => 'daniel',
                'name' => 'Daniel',
                'accent' => 'en-GB',
                'persona' => 'A traveller and office worker in his early thirties; the second half of '
                    .'most two-person exchanges.',
                'appearance' => 'A man in his early thirties, short dark brown hair, fair skin, '
                    .'clean-shaven, average build, usually in a dark green crew-neck jumper.',
            ],
            [
                'slug' => 'grace',
                'name' => 'Grace',
                'accent' => 'en-GB',
                'persona' => 'A hotel receptionist and, more broadly, the person behind any counter: '
                    .'calm, professional, used to being asked things.',
                'appearance' => 'A woman in her forties, dark curly hair to the shoulders, deep brown skin, '
                    .'warm smile, in a light grey blazer over a black top.',
            ],
            [
                'slug' => 'tomas',
                'name' => 'Tomas',
                'accent' => 'en-GB',
                'persona' => 'A shopkeeper and market trader; appears wherever buying, prices and '
                    .'quantities are being taught.',
                'appearance' => 'A man in his fifties, close-cropped grey hair, light brown skin, '
                    .'stocky build, short grey beard, in a dark blue work apron over a checked shirt.',
            ],
            [
                'slug' => 'aiko',
                'name' => 'Aiko',
                'accent' => 'en-US',
                'persona' => 'A doctor and pharmacist; carries the health, body and illness vocabulary.',
                'appearance' => 'A woman in her late thirties, straight black hair tied back, '
                    .'light skin, rectangular glasses, slim build, in a white clinical coat over a blue shirt.',
            ],
            [
                'slug' => 'omar',
                'name' => 'Omar',
                'accent' => 'en-GB',
                'persona' => 'A teacher and course tutor; appears in study, education and exam material.',
                'appearance' => 'A man in his forties, short black hair greying at the temples, '
                    .'medium brown skin, trimmed beard, tall, in a burgundy jumper over a collared shirt.',
            ],
            [
                'slug' => 'lena',
                'name' => 'Lena',
                'accent' => 'en-GB',
                'persona' => 'A colleague and manager; carries workplace, meetings and career vocabulary.',
                'appearance' => 'A woman in her late twenties, blonde hair in a low ponytail, '
                    .'pale skin, freckles, slim, in a charcoal blazer over a white shirt.',
            ],
            [
                'slug' => 'samuel',
                'name' => 'Samuel',
                'accent' => 'en-GB',
                'persona' => 'A neighbour and older relative; carries home, family, weather and '
                    .'everyday-routine vocabulary.',
                'appearance' => 'A man in his late sixties, thinning white hair, deep brown skin, '
                    .'reading glasses, slight build, in a brown cardigan.',
            ],
            [
                'slug' => 'nadia',
                'name' => 'Nadia',
                'accent' => 'en-GB',
                'persona' => 'A student in her early twenties; carries university, technology and '
                    .'social-life vocabulary.',
                'appearance' => 'A young woman in her early twenties, long dark hair with a centre parting, '
                    .'olive skin, small silver stud earrings, in a mustard-yellow sweatshirt.',
            ],
            [
                'slug' => 'peter',
                'name' => 'Peter',
                'accent' => 'en-GB',
                'persona' => 'A driver, guard and general public-transport figure; carries travel, '
                    .'directions and timetable vocabulary.',
                'appearance' => 'A man in his fifties, short sandy hair, fair weathered skin, '
                    .'broad build, in a high-visibility jacket over a navy uniform shirt.',
            ],
            [
                'slug' => 'ines',
                'name' => 'Ines',
                'accent' => 'en-US',
                'persona' => 'A chef and café owner; carries food, cooking and restaurant vocabulary.',
                'appearance' => 'A woman in her thirties, black hair in a bun under a cloth headband, '
                    .'brown skin, strong build, in a white chef\'s jacket.',
            ],
            [
                'slug' => 'joseph',
                'name' => 'Joseph',
                'accent' => 'en-GB',
                'persona' => 'A child of about nine; appears in family, school and play scenes where '
                    .'the vocabulary calls for a younger person.',
                'appearance' => 'A boy of about nine, short curly black hair, brown skin, '
                    .'gap-toothed smile, in a red striped t-shirt.',
            ],
            [
                'slug' => 'clara',
                'name' => 'Clara',
                'accent' => 'en-GB',
                'persona' => 'A nurse and carer; appears alongside Aiko in health settings and in '
                    .'scenes about helping people.',
                'appearance' => 'A woman in her fifties, short grey bob, pale skin, kind lined face, '
                    .'in a light blue tunic.',
            ],
            [
                'slug' => 'raj',
                'name' => 'Raj',
                'accent' => 'en-GB',
                'persona' => 'An engineer and repairer; carries tools, machines, describing-problems '
                    .'and fixing-things vocabulary.',
                'appearance' => 'A man in his thirties, black hair, medium brown skin, full beard, '
                    .'average build, in a grey work shirt with rolled sleeves.',
            ],
        ];
    }
}
