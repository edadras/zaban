<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vocabulary_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('language_id')->constrained()->cascadeOnDelete();
            $table->string('headword');
            $table->string('normalised')->comment('lowercased, accent-folded lookup key');
            $table->foreignId('primary_part_of_speech_id')->nullable()->constrained('parts_of_speech')->nullOnDelete();
            $table->foreignId('cefr_level_id')->nullable()->constrained('cefr_levels')->nullOnDelete();
            $table->unsignedInteger('frequency_rank')->nullable();
            $table->string('ipa')->nullable();
            $table->foreignId('word_family_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['language_id', 'normalised', 'primary_part_of_speech_id'], 'vocab_lang_norm_pos_unique');
            $table->index('frequency_rank');
            $table->index('cefr_level_id');
        });

        Schema::create('word_families', function (Blueprint $table) {
            $table->id();
            $table->foreignId('language_id')->constrained()->cascadeOnDelete();
            $table->string('stem');
            $table->timestamps();
            $table->unique(['language_id', 'stem']);
        });

        Schema::table('vocabulary_items', function (Blueprint $table) {
            $table->foreign('word_family_id')->references('id')->on('word_families')->nullOnDelete();
        });

        // Inflected/derived surface forms, so "went" resolves to the "go" item.
        Schema::create('word_forms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vocabulary_item_id')->constrained()->cascadeOnDelete();
            $table->string('form');
            $table->string('normalised');
            $table->string('form_type', 48); // base|past|past_participle|plural|comparative|...
            $table->boolean('is_irregular')->default(false);
            $table->timestamps();

            $table->unique(['vocabulary_item_id', 'form_type', 'normalised'], 'word_forms_item_type_norm_unique');
            $table->index('normalised');
        });

        // A word means several things; mastery is tracked per sense, not per spelling.
        Schema::create('vocabulary_senses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vocabulary_item_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sense_number')->default(1);
            $table->foreignId('part_of_speech_id')->nullable()->constrained('parts_of_speech')->nullOnDelete();
            $table->foreignId('cefr_level_id')->nullable()->constrained('cefr_levels')->nullOnDelete();
            $table->foreignId('topic_id')->nullable()->constrained()->nullOnDelete();
            $table->string('register', 32)->nullable();   // neutral|formal|informal|slang|literary
            $table->string('domain', 64)->nullable();     // business|medical|academic|...
            $table->timestamps();

            $table->unique(['vocabulary_item_id', 'sense_number']);
        });

        Schema::create('definitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vocabulary_sense_id')->constrained()->cascadeOnDelete();
            $table->foreignId('language_id')->constrained()->comment('language the definition is written in');
            $table->foreignId('cefr_level_id')->nullable()->constrained('cefr_levels')->nullOnDelete()
                ->comment('reading level of the gloss, so beginners get simpler wording');
            $table->text('text');
            $table->string('generation_method', 32)->default('authored');
            $table->timestamps();

            $table->index(['vocabulary_sense_id', 'language_id']);
        });

        Schema::create('translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vocabulary_sense_id')->constrained()->cascadeOnDelete();
            $table->foreignId('language_id')->constrained()->cascadeOnDelete();
            $table->string('text');
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->unique(['vocabulary_sense_id', 'language_id', 'text'], 'translations_sense_lang_text_unique');
        });

        // Examples attach to senses, grammar rules and phrases alike.
        Schema::create('examples', function (Blueprint $table) {
            $table->id();
            $table->morphs('exemplifiable');
            $table->foreignId('language_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cefr_level_id')->nullable()->constrained('cefr_levels')->nullOnDelete();
            $table->text('text');
            $table->text('translation')->nullable();
            $table->foreignId('media_asset_id')->nullable();
            $table->string('generation_method', 32)->default('authored');
            $table->string('copyright_status', 32)->default('owned');
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('collocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vocabulary_sense_id')->constrained()->cascadeOnDelete();
            $table->string('pattern');                       // "make a decision"
            $table->string('collocation_type', 48)->nullable(); // verb_noun|adj_noun|...
            $table->unsignedInteger('frequency')->nullable();
            $table->timestamps();

            $table->index('vocabulary_sense_id');
        });

        // Symmetric lexical relations between senses (synonym/antonym), stored once
        // with a type discriminator rather than as two near-identical tables.
        Schema::create('sense_relations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_sense_id')->constrained('vocabulary_senses')->cascadeOnDelete();
            $table->foreignId('to_sense_id')->constrained('vocabulary_senses')->cascadeOnDelete();
            $table->string('relation_type', 32); // synonym|antonym|hypernym|hyponym|confusable
            $table->decimal('strength', 4, 3)->default(1.000);
            $table->timestamps();

            $table->unique(['from_sense_id', 'to_sense_id', 'relation_type'], 'sense_relations_unique');
            $table->index('relation_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sense_relations');
        Schema::dropIfExists('collocations');
        Schema::dropIfExists('examples');
        Schema::dropIfExists('translations');
        Schema::dropIfExists('definitions');
        Schema::dropIfExists('vocabulary_senses');
        Schema::dropIfExists('word_forms');
        Schema::table('vocabulary_items', function (Blueprint $table) {
            $table->dropForeign(['word_family_id']);
        });
        Schema::dropIfExists('word_families');
        Schema::dropIfExists('vocabulary_items');
    }
};
