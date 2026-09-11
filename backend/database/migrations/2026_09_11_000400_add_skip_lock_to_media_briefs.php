<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A decision a person made, which re-planning must not undo.
 *
 * The builder skips briefs by its own rules and un-skips them when those rules
 * change, which is right. But some briefs are skipped for reasons no rule can
 * see: the lesson teaches shop signs and every brief forbids writing in the
 * image, so the artwork cannot contain the thing being taught. That judgement
 * was made seven times by reading the prompts, and the next `media:briefs`
 * quietly put all seven back in the queue to be paid for.
 *
 * A locked skip is not resurrected. `media:briefs` counts them instead, so the
 * decisions stay visible rather than silently permanent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_briefs', function (Blueprint $table) {
            $table->boolean('skip_locked')->default(false)->after('skip_reason');
        });
    }

    public function down(): void
    {
        Schema::table('media_briefs', function (Blueprint $table) {
            $table->dropColumn('skip_locked');
        });
    }
};
