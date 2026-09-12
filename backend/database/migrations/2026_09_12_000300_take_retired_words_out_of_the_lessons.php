<?php

use App\Models\Exercise;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A word that is no longer taught must also stop appearing.
 *
 * Retiring a concept stops the next build from making anything out of it, and
 * does nothing about what the last build already made. The flashcard for
 * "nhraca" was still in its lesson, in front of a learner, with the catalogue
 * behind it saying the word does not exist.
 *
 * This clears the cards and the exercises that were derived from a retired
 * concept. The concept itself stays, with its reason, because it is the record
 * of what the page was read as.
 */
return new class extends Migration
{
    public function up(): void
    {
        $retired = DB::table('concepts')->whereNotNull('retired_reason')->pluck('id');

        if ($retired->isEmpty()) {
            return;
        }

        DB::table('lesson_blocks')
            ->where('type', 'flashcard')
            ->whereIn(DB::raw("JSON_UNQUOTE(JSON_EXTRACT(config, '$.concept_id'))"), $retired->map('strval'))
            ->delete();

        $exercises = DB::table('exercise_concepts')->whereIn('concept_id', $retired)
            ->distinct()->pluck('exercise_id');

        if ($exercises->isEmpty()) {
            return;
        }

        // Only what was derived: an exercise lifted from the book's own pages
        // is the book's, and a shared concept is not a reason to delete it.
        $derived = DB::table('exercises')->whereIn('id', $exercises)
            ->where('generation_method', 'like', 'derived%')->pluck('id');

        if ($derived->isEmpty()) {
            return;
        }

        DB::table('lesson_blocks')->whereIn('exercise_id', $derived)
            ->update(['exercise_id' => null]);
        DB::table('exercise_options')->whereIn('exercise_id', $derived)->delete();
        DB::table('exercise_answers')->whereIn('exercise_id', $derived)->delete();
        DB::table('exercise_concepts')->whereIn('exercise_id', $derived)->delete();
        DB::table('content_reviews')->where('reviewable_type', Exercise::class)
            ->whereIn('reviewable_id', $derived)->delete();
        DB::table('exercises')->whereIn('id', $derived)->delete();
    }

    /**
     * Nothing to put back.
     *
     * What this removes was derived from a word the catalogue has since said it
     * does not teach. `content:build-activities` rebuilds every derived card and
     * item from whatever the catalogue says at the time, so restoring the state
     * is a re-run, not an undo.
     */
    public function down(): void {}
};
