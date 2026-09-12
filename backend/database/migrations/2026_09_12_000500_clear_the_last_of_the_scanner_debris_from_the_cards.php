<?php

use App\Models\VocabularySense;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The last cards a learner could not learn anything from.
 *
 * Two leftovers from the same scanner failure. Five more two- and three-letter
 * misreads that the previous pass did not list - "ta", "ar", "ara", "Tho",
 * "af" - and twenty-seven cards whose back is a sentence read the same way:
 * "Tho hact ta racard A calincatiann ic in A nhraca ar A cantanca". These are
 * senses the books never glossed and the Persian catalogue never reached, so
 * the card fell through to its example sentence and the example is debris.
 *
 * Nothing is guessed here either. The misreads are retired with their reason;
 * the cards go, and the mined sentence behind each one goes with them so the
 * next build cannot pick it up again. What stays is the vocabulary row itself,
 * which is the record of what the page was read as.
 */
return new class extends Migration
{
    private const DEBRIS = ['ta', 'ar', 'ara', 'Tho', 'af'];

    private const REASON = 'too short to read again: the scanner invented it and no second read can settle it';

    /** Cards whose back is a sentence the scanner mangled past reading. */
    private const UNREADABLE_CARDS = [
        5964, 5965, 6149, 6150, 6366, 6930, 6932, 6933, 6961, 6986, 7097, 7226,
        7339, 10231, 10234, 10269, 10569, 10694, 11116, 11124, 11238, 26525,
        27201, 28889, 28890, 28899, 28906,
    ];

    public function up(): void
    {
        $concepts = $this->concepts();

        DB::table('concepts')->whereIn('id', $concepts)
            ->update(['is_active' => false, 'retired_reason' => self::REASON, 'updated_at' => now()]);

        DB::table('lesson_blocks')
            ->where('type', 'flashcard')
            ->whereIn(DB::raw("JSON_UNQUOTE(JSON_EXTRACT(config, '$.concept_id'))"), $concepts->map('strval'))
            ->delete();

        // The sentence itself, so the next build cannot reach for it again.
        $texts = DB::table('lesson_blocks')->whereIn('id', self::UNREADABLE_CARDS)
            ->get(['config'])
            ->map(fn ($b) => json_decode((string) $b->config, true)['back'] ?? null)
            ->filter()->unique()->values();

        DB::table('lesson_blocks')->whereIn('id', self::UNREADABLE_CARDS)->delete();

        if ($texts->isNotEmpty()) {
            DB::table('examples')->whereIn('text', $texts->all())->delete();
        }
    }

    public function down(): void
    {
        DB::table('concepts')->whereIn('id', $this->concepts())
            ->update(['is_active' => true, 'retired_reason' => null, 'updated_at' => now()]);

        // The cards and the mined sentences are rebuilt by
        // `content:build-activities`, which is where both came from.
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
