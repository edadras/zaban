<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A source page often prints several separate exchanges under one heading.
 * Without an ordinal they all share (document, page, title) and the extractor
 * silently overwrites each with the next - 80 dialogues parsed, 50 stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dialogues', function (Blueprint $table) {
            $table->unsignedSmallInteger('source_sequence')->default(0)->after('source_page');
            $table->unique(
                ['source_document_id', 'source_page', 'source_sequence'],
                'dialogues_source_position_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('dialogues', function (Blueprint $table) {
            $table->dropUnique('dialogues_source_position_unique');
            $table->dropColumn('source_sequence');
        });
    }
};
