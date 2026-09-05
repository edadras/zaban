<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Character identity anchors.
 *
 * A recurring cast is worth real money in a language course - learners
 * remember people - but only if the same character actually looks the same in
 * unit 3 and unit 300. A text description alone does not achieve that: image
 * models drift. These three columns hold the mechanisms that do.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            // Provider-side trained identity (Higgsfield Soul). The strongest
            // guarantee available: every generation is anchored to it.
            $table->string('soul_id')->nullable()->after('appearance_prompt');

            // Canonical portrait, passed as a reference image to providers that
            // accept one. Weaker than a Soul id, but works on any provider.
            $table->foreignId('reference_media_asset_id')->nullable()->after('soul_id')
                ->constrained('media_assets')->nullOnDelete();

            // Optional rigged GLB, for the interactive/3D route. Kept here so a
            // character has one identity record whichever pipeline renders it.
            $table->string('model_3d_url')->nullable()->after('reference_media_asset_id');
            $table->string('model_3d_status', 24)->nullable()->after('model_3d_url');

            $table->index('soul_id');
        });
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->dropIndex(['soul_id']);
            $table->dropForeign(['reference_media_asset_id']);
            $table->dropColumn(['soul_id', 'reference_media_asset_id', 'model_3d_url', 'model_3d_status']);
        });
    }
};
