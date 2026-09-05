<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Video briefs need two things a still does not.
 *
 * A duration, because a clip is billed and rendered by its length; and a link
 * back to the still it animates. That link is the important one: video models
 * drift far worse than image models, and a cast of fourteen would be
 * unrecognisable across a thousand independently generated clips. Seeding each
 * clip from the lesson's own already-approved scene image is what keeps the
 * same people in it - so a video brief is not renderable until its source
 * image has been generated and imported.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_briefs', function (Blueprint $table) {
            $table->unsignedSmallInteger('duration_seconds')->nullable()->after('resolution');

            // The still this clip animates. Null for stills themselves.
            $table->unsignedBigInteger('source_brief_id')->nullable()->after('media_asset_id');
            $table->index('source_brief_id');
        });
    }

    public function down(): void
    {
        Schema::table('media_briefs', function (Blueprint $table) {
            $table->dropIndex(['source_brief_id']);
            $table->dropColumn(['duration_seconds', 'source_brief_id']);
        });
    }
};
