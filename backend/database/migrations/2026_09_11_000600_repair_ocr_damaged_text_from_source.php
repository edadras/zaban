<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Text the first OCR pass got wrong, read again off the page it came from.
 *
 * A handful of lines came back through a consistent confusion - s->c, e and o
 * ->a, p->n, g->o, y->u, b->h - which turns "has an intense dislike of the late
 * King's eldest son" into "hac an intanca diclike af tha late Kino's aldest
 * can". Twenty-one rows out of roughly forty-eight thousand, so this is a
 * scattering rather than a bad book; the pages around them read fine.
 *
 * None of these are guesses. The confusion is regular enough to decode by eye,
 * but decoding is still inventing text for a course someone will learn from, so
 * every line below was recovered by re-rendering its page from the source PDF
 * at 400 dpi and reading it again - the words are the book's. The first pass
 * had simply rendered too small for these lines.
 *
 * Two techniques, both worth keeping:
 *
 * - Body text: re-render the page at 400 dpi and read it whole. What failed at
 *   the original resolution succeeds at this one.
 * - Headings: crop the top band of the page and read it alone with --psm 7. A
 *   whole-page pass misreads large display type badly ("setae ac eae ting ee
 *   SOHC REN"), and the same header cropped reads "13 Other languages".
 *
 * Three rows had no recoverable text at all - the damaged string was page
 * furniture that the extractor mistook for a heading. Those take their unit's
 * own title, which is what a unit holding exactly one lesson means anyway, and
 * again is the book's wording rather than mine.
 *
 * Rows are addressed by id and the old value is kept, so `down` restores
 * exactly what was there.
 */
return new class extends Migration
{
    /** table => column => [id => [damaged, repaired]] */
    private const REPAIRS = [
        'lessons' => ['title' => [
            // Collocations Advanced p6: "...the way English words are closely
            // associated with each other."
            290 => ['clacaly aceariatad with aach ather', 'closely associated with each other'],
            // Collocations Advanced p18: "...we use words in a non-literal
            // sense. For example, when we..."
            299 => ['sence Faravamnla whan ven', 'sense. For example, when we'],
            // Collocations Advanced p106.
            352 => [
                'Min rantidntt haliava tha chaar auantitu af fand an tha tahle [tho curnricinoly larce amauntl',
                'We couldn’t believe the sheer quantity of food on the table. [the surprisingly large amount]',
            ],
            // Collocations Intermediate p96.
            395 => [
                'Matan: Auinn dawn think thic chaace ic had? It hac ctrane cmall Have tacte tell',
                'Mateo: Quinn, do you think this cheese is bad? It has a strong smell. Have a taste, tell me',
            ],
            // No text to recover: the heading was page furniture. Grammar
            // Advanced Unit 27, whose title the unit already carries.
            622 => ['= I A tage can gta wh enh', 'Negative questions; echo questions; questions with that-clauses'],
            // Grammar Basic Unit 69. The heading loses its spaces in every
            // pass, including the contents page; the body of p149 spells it
            // out: "We say: go to work, be at work, start work, finish work".
            722 => ['gotowork gohome goto the hospital — section A', 'go to work, go home, go to the hospital — section A'],
            // Idioms Advanced Unit 13, header cropped from p32.
            1323 => ['setae ac eae ting ee SOHC REN', 'Other languages'],
            // Idioms Intermediate p50, confirmed again on p51.
            1397 => ['Dm in tha taachar’c annd hanks', 'I’m in the teacher’s good books'],
            // Phrasal Verbs Intermediate p84 is "39 Persuading / Verbs with
            // talk and put"; the damaged string was half an example sentence
            // ("He's an excellent teacher. He puts..."), never a title.
            1597 => ['Late an aveallent teacher Ho nue.', 'Verbs with talk and put'],
        ]],
        'units' => ['title' => [
            // Collocations Intermediate p80 and p84.
            198 => ['Hara ara cama warhe which aftan callacata with', 'Here are some verbs which often collocate with money'],
            200 => ['lank at thaca avtracte fram lattare ta an intarnatinnal macazina', 'Look at these extracts from letters to an international magazine'],
            449 => ['gotowork gohome goto the hospital', 'go to work, go home, go to the hospital'],
            651 => ['expressions in this unit all come from Latin or French.', 'Other languages'],
        ]],
        'concepts' => ['label' => [
            5387 => ['hac an intanca diclike af tha late Kino’s aldest can', 'has an intense dislike of the late King’s eldest son'],
            // Trailing scanner noise on an otherwise clean label. Trimmed, not
            // rewritten.
            7997 => ['Gradable and non-gradable adjectives 1 — = een', 'Gradable and non-gradable adjectives 1'],
            7999 => ['Adjectives + to-infinitive, -ing, that-clause, wh-clause — ati', 'Adjectives + to-infinitive, -ing, that-clause, wh-clause'],
            8045 => ['canand could — could/couldn’t:', 'can and could'],
            8069 => ['gotowork gohome goto the hospital — Ee', 'go to work, go home, go to the hospital'],
            9873 => ['hac A verv good effect on', 'has a very good effect on'],
        ]],
        'vocabulary_items' => ['headword' => [
            4815 => ['hac an intanca diclike af tha late Kino’s aldest can', 'has an intense dislike of the late King’s eldest son'],
            7499 => ['hac A verv good effect on', 'has a very good effect on'],
        ]],
    ];

    public function up(): void
    {
        $this->apply(1);
    }

    public function down(): void
    {
        $this->apply(0);
    }

    private function apply(int $to): void
    {
        foreach (self::REPAIRS as $table => $columns) {
            foreach ($columns as $column => $rows) {
                foreach ($rows as $id => $pair) {
                    DB::table($table)->where('id', $id)->update([$column => $pair[$to]]);
                }
            }
        }
    }
};
