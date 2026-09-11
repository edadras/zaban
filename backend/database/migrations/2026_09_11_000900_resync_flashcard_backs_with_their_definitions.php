<?php

use App\Models\VocabularySense;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Flashcards still showing the gloss that had landed on the wrong word.
 *
 * A flashcard keeps its own copy of the meaning so the lesson screen can open
 * without a round trip per word, which means correcting `definitions` does not
 * on its own correct what a learner sees: the card for "cram" went on reading
 * "diagram that lays out ideas for a topic..." after the catalogue beneath it
 * had been put right.
 *
 * This puts the copies back in step with the definitions they are copies of.
 * The rule is the one the builder uses - the first definition of the sense that
 * was not set aside as ambiguous - so a rebuild produces the same cards.
 *
 * Cards whose sense has no definition are left alone. They show an example
 * sentence instead, which is what the builder falls back to, and that is a
 * separate question from this one.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->resync();
    }

    /**
     * Nothing to undo on its own.
     *
     * The card is a copy, not a source. Rolling back the migration beneath this
     * one restores the definitions; running the migrations forward again, or
     * `activities:build`, copies them back onto the cards.
     */
    public function down(): void
    {
        $this->resync();
    }

    private function resync(): void
    {
        $best = DB::table('definitions as d')
            ->joinSub(
                DB::table('definitions')
                    ->where('generation_method', '!=', 'extracted_ambiguous')
                    ->groupBy('vocabulary_sense_id')
                    ->selectRaw('vocabulary_sense_id, MIN(id) as id'),
                'first',
                'first.id',
                '=',
                'd.id',
            )
            ->pluck('d.text', 'd.vocabulary_sense_id');

        $senseOfConcept = DB::table('concepts')
            ->where('conceptable_type', VocabularySense::class)
            ->pluck('conceptable_id', 'id');

        DB::table('lesson_blocks')
            ->where('type', 'flashcard')
            ->orderBy('id')
            ->chunk(500, function ($blocks) use ($best, $senseOfConcept) {
                foreach ($blocks as $block) {
                    $config = json_decode((string) $block->config, true);
                    if (! is_array($config) || ! isset($config['concept_id'])) {
                        continue;
                    }

                    $senseId = $senseOfConcept[$config['concept_id']] ?? null;
                    $definition = $senseId === null ? null : ($best[$senseId] ?? null);

                    if ($definition === null || ($config['back'] ?? null) === $definition) {
                        continue;
                    }

                    $config['back'] = $definition;

                    DB::table('lesson_blocks')->where('id', $block->id)->update([
                        'config' => json_encode($config, JSON_UNESCAPED_UNICODE),
                        'updated_at' => now(),
                    ]);
                }
            });
    }
};
