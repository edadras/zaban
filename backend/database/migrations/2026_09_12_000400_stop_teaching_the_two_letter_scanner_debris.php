<?php

use App\Models\VocabularySense;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The short misreads, which the first pass over the damage could not see.
 *
 * Recovering a mangled word by reading its page again works when the word is
 * long enough to match on: "imnartant" has only one candidate. At two and three
 * letters there is nothing to match - "tha", "ic", "af", "vou" could be almost
 * anything, and a second read returns a different almost-anything each time.
 *
 * So they are not repaired, they are retired. Every one below is a word the
 * scanner invented: not English, not one of the abbreviations these books teach
 * (TV, GP, CV, PhD), and not one of their affixes (-ity, -ise, im-, un-). The
 * rows stay, with their reason, because they are the record of what the page
 * was read as; they simply stop reaching a learner, who was being shown a card
 * that said "tha" and asked to recall what it means.
 *
 * "ee", "ou" and "oo" go with them for a different reason that reaches the same
 * place: they are the pronunciation book's spelling patterns, caught by the
 * vocabulary extractor as if they were words.
 */
return new class extends Migration
{
    private const DEBRIS = [
        'fi', 'tha', 'ThA', 'ic', 'Tha', 'to®', 'aat', 'ona', 'hac', 'mv', 'r—',
        'ite', 'vou', 'at®', 'ng', 'fae', 'ee', 'ou', 'oo', 'ei', 'arn', 'cav',
        'up~', 'im', 'p', 'h', 'k',
    ];

    private const REASON = 'too short to read again: the scanner invented it and no second read can settle it';

    public function up(): void
    {
        $concepts = $this->concepts();

        DB::table('concepts')->whereIn('id', $concepts)
            ->update(['is_active' => false, 'retired_reason' => self::REASON, 'updated_at' => now()]);

        DB::table('lesson_blocks')
            ->where('type', 'flashcard')
            ->whereIn(DB::raw("JSON_UNQUOTE(JSON_EXTRACT(config, '$.concept_id'))"),
                $concepts->map('strval'))
            ->delete();
    }

    public function down(): void
    {
        DB::table('concepts')->whereIn('id', $this->concepts())
            ->update(['is_active' => true, 'retired_reason' => null, 'updated_at' => now()]);

        // The cards come back with `content:build-activities`, which is where
        // they came from in the first place.
    }

    /** @return Collection<int, int> */
    private function concepts()
    {
        return DB::table('concepts')
            ->where('conceptable_type', VocabularySense::class)
            ->whereIn('label', self::DEBRIS)
            ->pluck('id');
    }
};
