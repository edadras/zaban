<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Unit and lesson headings the scanner could not read, taken off the page.
 *
 * Display type is what the first OCR pass handled worst. Body text on the same
 * page came out fine while the heading above it became "eS TUSTIN" or
 * "ULI jal _ Discussing", and a second class of failure simply lost the spaces:
 * "Filmandbookreviews", "Makingthingseasier", "Townand country life".
 *
 * Recovered the same way as the body text before it, with one change that does
 * the work: crop the top band of the page and read that alone with --psm 7. A
 * whole-page pass has to settle on one segmentation for body and display type
 * together and gets the big type wrong; the header on its own reads cleanly.
 * "58 Discussing issues", "33 Personal finance", "21 Shakespeare", "25 Cause
 * and effect" all came back that way, and the unit number in each confirms the
 * page is the right one.
 *
 * Only headings where the crop came back unambiguous are here. Fourteen more
 * are still damaged and deliberately left alone - the crop returned something
 * partial ("23 Learning a", "11 = Structuring and talking abc") or two lessons
 * in one unit pointed at different pages, and a heading a learner reads is not
 * the place to publish a good guess. They are listed in MEDIA_RUNBOOK.md.
 *
 * Where a lesson's own heading was page furniture rather than text - a scrap of
 * an example sentence, or a row of scanner noise - it takes its unit's heading,
 * which is what a unit holding one lesson means anyway.
 */
return new class extends Migration
{
    /** table => column => [id => [damaged, repaired]] */
    private const REPAIRS = [
        'units' => ['title' => [
            130 => ['Filmandbookreviews', 'Film and book reviews'],
            133 => ['Townand country life', 'Town and country life'],
            134 => ['Personal', 'Personal finance'],
            149 => ['Makingthingseasier', 'Making things easier'],
            153 => ['Stopping and st arting', 'Stopping and starting'],
            159 => ['ULI jal _ Discussing', 'Discussing issues'],
            659 => ['lago: O, beware, my lord, of jealousy; It', 'Shakespeare'],
            824 => ['Ameriean and Australian P', 'American and Australian phrasal verbs'],
            826 => ['Unit 11', 'Put'],
            840 => ['trigger off', 'Cause and effect'],
            847 => ['Describing people and pla', 'Describing people and places'],
            865 => ['Client speak to Mr Jones, please?', 'Calling people'],
        ]],
        'lessons' => ['title' => [
            // Collocations Advanced p68: "Read this extract from a report on
            // getting rid of waste."
            329 => ['catting rid af wacte', 'getting rid of waste'],
            // The rest had no heading of their own to recover.
            308 => ['raach', 'Marketing'],
            327 => ['Filmandbookreviews (A)', 'Film and book reviews — section A'],
            330 => [',ZE oes =', 'Town and country life'],
            333 => ['Bac ho J', 'Personal finance'],
            348 => ['This', 'Making things easier'],
            361 => ['ULI jal _ Discussing — section A', 'Discussing issues — section A'],
            1330 => ['ms Can lass Geman hale', 'Shakespeare'],
            1332 => ['lago: O, beware, my lord, of jealousy; It — section E', 'Shakespeare — section E'],
            1572 => ['Ameriean and Australian P — section A', 'American and Australian phrasal verbs — section A'],
            1574 => ['TTT TE STF EWEN WE HOLD HISG LIS Par re | 3 BB', 'Put'],
            1583 => ['narticle', 'Cause and effect'],
            1590 => ['Describing people and pla — section A', 'Describing people and places — section A'],
            1604 => ['akemaY-Yololls', 'Calling people'],
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
