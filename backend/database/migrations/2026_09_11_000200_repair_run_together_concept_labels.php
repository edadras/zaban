<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Three concept labels lost their spaces in OCR.
 *
 * "Haveyougotany" is wrong twice over. A learner meets it as the name of the
 * thing they are supposed to be learning, which is the more serious half; and
 * it is also fed verbatim into the artwork prompt for that lesson, so the
 * course would pay a provider to illustrate a word that does not exist.
 *
 * Three rows out of twenty thousand, found by reading a batch of prompts before
 * spending on them rather than by any check - which is the useful part of the
 * finding. The labels are corrected here rather than filtered at the point of
 * use, because the text is wrong wherever it is read.
 */
return new class extends Migration
{
    /** Broken label => what the book actually prints. */
    private const REPAIRS = [
        'Haveyougotany' => 'Have you got any',
        'anonlychild' => 'an only child',
        'Doyoucomefromabigfamily?' => 'Do you come from a big family?',
    ];

    public function up(): void
    {
        foreach (self::REPAIRS as $broken => $correct) {
            DB::table('concepts')->where('label', $broken)->update(['label' => $correct]);
        }
    }

    public function down(): void
    {
        foreach (self::REPAIRS as $broken => $correct) {
            DB::table('concepts')->where('label', $correct)->update(['label' => $broken]);
        }
    }
};
