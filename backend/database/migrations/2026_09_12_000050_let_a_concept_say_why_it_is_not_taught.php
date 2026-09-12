<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A decision not to teach a word has to survive the next build.
 *
 * `is_active` is recomputed from scratch on every pass: the stage that separates
 * headwords from headings turns every concept back on and then switches off the
 * ones that read as a heading. A word set aside for any other reason - a misread
 * the page could not settle, a person's name from an example - came straight
 * back on the next run, and the flashcard for "nhraca" returned with it.
 *
 * Recording the reason is what makes the decision durable, and it is also what
 * makes it reviewable: a row switched off with nothing written against it is
 * indistinguishable from a slip.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('concepts', function (Blueprint $table) {
            $table->string('retired_reason')->nullable()->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('concepts', function (Blueprint $table) {
            $table->dropColumn('retired_reason');
        });
    }
};
