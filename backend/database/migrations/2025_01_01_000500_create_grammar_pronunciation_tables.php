<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grammar_concepts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('language_id')->constrained()->cascadeOnDelete();
            $table->string('slug')->unique();
            $table->string('title');                          // "Present Perfect"
            $table->text('summary')->nullable();
            $table->foreignId('cefr_level_id')->nullable()->constrained('cefr_levels')->nullOnDelete();
            $table->string('category', 64)->nullable();        // tense|article|preposition|word_order|...
            $table->timestamps();
            $table->softDeletes();

            $table->index('cefr_level_id');
            $table->index('category');
        });

        Schema::create('grammar_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grammar_concept_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('statement');
            $table->string('formula')->nullable();             // "have/has + past participle"
            $table->foreignId('cefr_level_id')->nullable()->constrained('cefr_levels')->nullOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('generation_method', 32)->default('authored');
            $table->timestamps();
        });

        Schema::create('phonemes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('language_id')->constrained()->cascadeOnDelete();
            $table->string('ipa', 16);
            $table->string('arpabet', 16)->nullable();         // aligner-friendly label
            $table->string('type', 24);                        // consonant|vowel|diphthong
            $table->json('features')->nullable();              // place/manner/voicing
            $table->text('articulation_hint')->nullable();
            $table->timestamps();

            $table->unique(['language_id', 'ipa']);
        });

        Schema::create('pronunciation_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('language_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vocabulary_item_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('text');
            $table->string('ipa');
            $table->string('accent', 16)->default('GA');       // GA|RP|AusE...
            $table->foreignId('cefr_level_id')->nullable()->constrained('cefr_levels')->nullOnDelete();
            $table->foreignId('media_asset_id')->nullable()->comment('reference model audio');
            $table->timestamps();

            $table->index('vocabulary_item_id');
        });

        // Ordered phoneme sequence for a pronunciation item, used by the aligner
        // to score per-phoneme accuracy rather than whole-word pass/fail.
        Schema::create('pronunciation_item_phonemes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pronunciation_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('phoneme_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->boolean('is_stressed')->default(false);
            $table->timestamps();

            $table->unique(['pronunciation_item_id', 'position'], 'pron_item_phoneme_pos_unique');
        });

        Schema::create('minimal_pairs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('language_id')->constrained()->cascadeOnDelete();
            $table->foreignId('phoneme_a_id')->constrained('phonemes')->cascadeOnDelete();
            $table->foreignId('phoneme_b_id')->constrained('phonemes')->cascadeOnDelete();
            $table->foreignId('item_a_id')->constrained('pronunciation_items')->cascadeOnDelete();
            $table->foreignId('item_b_id')->constrained('pronunciation_items')->cascadeOnDelete();
            $table->foreignId('cefr_level_id')->nullable()->constrained('cefr_levels')->nullOnDelete();
            $table->timestamps();

            $table->unique(['item_a_id', 'item_b_id']);
            $table->index(['phoneme_a_id', 'phoneme_b_id']);
        });

        Schema::create('stress_patterns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('language_id')->constrained()->cascadeOnDelete();
            $table->string('code', 32);                        // Oo, oO, Ooo...
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();
            $table->unique(['language_id', 'code']);
        });

        Schema::create('intonation_patterns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('language_id')->constrained()->cascadeOnDelete();
            $table->string('code', 32);                        // rising|falling|fall_rise
            $table->string('name');
            $table->text('description')->nullable();
            $table->text('usage_note')->nullable();
            $table->timestamps();
            $table->unique(['language_id', 'code']);
        });

        // Multi-word lexical units: idioms, phrasal verbs and functional exponents
        // share one table with a type discriminator plus type-specific columns.
        Schema::create('phrases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('language_id')->constrained()->cascadeOnDelete();
            $table->string('text');
            $table->string('normalised');
            $table->string('phrase_type', 32); // idiom|phrasal_verb|collocation|functional|fixed
            $table->text('meaning')->nullable();
            $table->foreignId('cefr_level_id')->nullable()->constrained('cefr_levels')->nullOnDelete();
            $table->string('register', 32)->nullable();
            $table->boolean('is_separable')->nullable()->comment('phrasal verbs only');
            $table->foreignId('particle_verb_id')->nullable()->constrained('vocabulary_items')->nullOnDelete();
            $table->string('function_code', 64)->nullable()->comment('e.g. apologising, agreeing');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['language_id', 'normalised', 'phrase_type'], 'phrases_lang_norm_type_unique');
            $table->index('phrase_type');
            $table->index('function_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('phrases');
        Schema::dropIfExists('intonation_patterns');
        Schema::dropIfExists('stress_patterns');
        Schema::dropIfExists('minimal_pairs');
        Schema::dropIfExists('pronunciation_item_phonemes');
        Schema::dropIfExists('pronunciation_items');
        Schema::dropIfExists('phonemes');
        Schema::dropIfExists('grammar_rules');
        Schema::dropIfExists('grammar_concepts');
    }
};
