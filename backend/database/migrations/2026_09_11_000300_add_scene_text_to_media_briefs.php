<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The one sentence that describes this scene and nothing else.
 *
 * A brief's `prompt` is that sentence wrapped in four lines of shared house
 * style and a long list of exclusions - fine when the request renders one
 * picture, useless when nine briefs have to be asked for on a single sheet.
 * Concatenating nine full prompts gives the model ten thousand characters in
 * which the same rules are repeated nine times, and it loses track of which
 * scene belongs in which cell.
 *
 * So the scene is kept separately from its packaging. The sheet composer states
 * the house style once and then just numbers the scenes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_briefs', function (Blueprint $table) {
            $table->text('scene')->nullable()->after('prompt');
        });
    }

    public function down(): void
    {
        Schema::table('media_briefs', function (Blueprint $table) {
            $table->dropColumn('scene');
        });
    }
};
