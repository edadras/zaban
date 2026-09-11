<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The same lost spaces, in the table the vocabulary cards read from.
 *
 * An earlier migration repaired three concept labels that came out of OCR with
 * their spaces gone. It repaired only `concepts`. The same words are also
 * headwords in `vocabulary_items`, and that is the column a vocabulary card is
 * built from - so "Haveyougotany" was still going to be shown to a learner as
 * the word they are learning, and still going to be sent to a provider as the
 * word to illustrate.
 *
 * Two more turned up when the whole catalogue was swept rather than the three
 * that happened to be in one batch of prompts: "absolutelymarvellous" and
 * "missedthebus", both broken in `concepts` as well.
 *
 * The sweep that found them segments a spaceless label into dictionary words
 * and flags anything that splits three ways. It is not run in the application -
 * it over-reports British -ise spellings an American word list does not have
 * ("organisation" splits as "organ is at ion") - so the repairs are listed
 * here, checked by eye, rather than applied by rule.
 */
return new class extends Migration
{
    /** Broken text => what the book actually prints. */
    private const REPAIRS = [
        'Haveyougotany' => 'Have you got any',
        'anonlychild' => 'an only child',
        'Doyoucomefromabigfamily?' => 'Do you come from a big family?',
        'absolutelymarvellous' => 'absolutely marvellous',
        'missedthebus' => 'missed the bus',
    ];

    public function up(): void
    {
        $this->apply(self::REPAIRS);
    }

    public function down(): void
    {
        $this->apply(array_flip(self::REPAIRS));
    }

    /** @param  array<string,string>  $repairs */
    private function apply(array $repairs): void
    {
        foreach ($repairs as $from => $to) {
            DB::table('vocabulary_items')->where('headword', $from)->update(['headword' => $to]);
            DB::table('concepts')->where('label', $from)->update(['label' => $to]);
        }
    }
};
