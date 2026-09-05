<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Constraints whose target table is created in a later migration than the
 * referencing column. Kept together here so the earlier migrations stay readable
 * and the dependency direction is explicit in one place.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            $table->foreign('source_document_id')->references('id')->on('source_documents')->nullOnDelete();
        });

        Schema::table('lesson_blocks', function (Blueprint $table) {
            $table->foreign('exercise_id')->references('id')->on('exercises')->nullOnDelete();
            $table->foreign('media_asset_id')->references('id')->on('media_assets')->nullOnDelete();
            $table->foreign('dialogue_id')->references('id')->on('dialogues')->nullOnDelete();
        });

        Schema::table('examples', function (Blueprint $table) {
            $table->foreign('media_asset_id')->references('id')->on('media_assets')->nullOnDelete();
        });

        Schema::table('pronunciation_items', function (Blueprint $table) {
            $table->foreign('media_asset_id')->references('id')->on('media_assets')->nullOnDelete();
        });

        Schema::table('characters', function (Blueprint $table) {
            $table->foreign('avatar_media_asset_id')->references('id')->on('media_assets')->nullOnDelete();
        });

        Schema::table('dialogues', function (Blueprint $table) {
            $table->foreign('audio_media_asset_id')->references('id')->on('media_assets')->nullOnDelete();
            $table->foreign('source_document_id')->references('id')->on('source_documents')->nullOnDelete();
        });

        Schema::table('dialogue_turns', function (Blueprint $table) {
            $table->foreign('audio_media_asset_id')->references('id')->on('media_assets')->nullOnDelete();
        });

        Schema::table('passages', function (Blueprint $table) {
            $table->foreign('audio_media_asset_id')->references('id')->on('media_assets')->nullOnDelete();
            $table->foreign('source_document_id')->references('id')->on('source_documents')->nullOnDelete();
        });

        Schema::table('media_assets', function (Blueprint $table) {
            $table->foreign('ai_generation_id')->references('id')->on('ai_generations')->nullOnDelete();
        });

        Schema::table('exercises', function (Blueprint $table) {
            $table->foreign('ai_generation_id')->references('id')->on('ai_generations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('exercises', fn (Blueprint $t) => $t->dropForeign(['ai_generation_id']));
        Schema::table('media_assets', fn (Blueprint $t) => $t->dropForeign(['ai_generation_id']));
        Schema::table('passages', function (Blueprint $t) {
            $t->dropForeign(['audio_media_asset_id']);
            $t->dropForeign(['source_document_id']);
        });
        Schema::table('dialogue_turns', fn (Blueprint $t) => $t->dropForeign(['audio_media_asset_id']));
        Schema::table('dialogues', function (Blueprint $t) {
            $t->dropForeign(['audio_media_asset_id']);
            $t->dropForeign(['source_document_id']);
        });
        Schema::table('characters', fn (Blueprint $t) => $t->dropForeign(['avatar_media_asset_id']));
        Schema::table('pronunciation_items', fn (Blueprint $t) => $t->dropForeign(['media_asset_id']));
        Schema::table('examples', fn (Blueprint $t) => $t->dropForeign(['media_asset_id']));
        Schema::table('lesson_blocks', function (Blueprint $t) {
            $t->dropForeign(['exercise_id']);
            $t->dropForeign(['media_asset_id']);
            $t->dropForeign(['dialogue_id']);
        });
        Schema::table('lessons', fn (Blueprint $t) => $t->dropForeign(['source_document_id']));
    }
};
