<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('characters', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->text('persona')->nullable();
            $table->string('accent', 32)->nullable();
            $table->string('voice_id')->nullable()->comment('TTS provider voice handle');
            $table->foreignId('avatar_media_asset_id')->nullable();
            // Locked visual description so regenerated scenes keep the same face.
            $table->text('appearance_prompt')->nullable();
            $table->timestamps();
        });

        Schema::create('dialogues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('language_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cefr_level_id')->nullable()->constrained('cefr_levels')->nullOnDelete();
            $table->foreignId('topic_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->string('setting')->nullable();          // restaurant, airport...
            $table->text('summary')->nullable();
            $table->foreignId('audio_media_asset_id')->nullable();
            $table->string('generation_method', 32)->default('authored');
            $table->string('copyright_status', 32)->default('owned');
            $table->foreignId('source_document_id')->nullable();
            $table->unsignedInteger('source_page')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('cefr_level_id');
        });

        Schema::create('dialogue_turns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dialogue_id')->constrained()->cascadeOnDelete();
            $table->foreignId('character_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('position');
            $table->text('text');
            $table->text('translation')->nullable();
            $table->foreignId('audio_media_asset_id')->nullable();
            $table->unsignedInteger('audio_start_ms')->nullable();
            $table->unsignedInteger('audio_end_ms')->nullable();
            // Marks the turn the learner performs in roleplay mode.
            $table->boolean('is_learner_turn')->default(false);
            $table->timestamps();

            $table->unique(['dialogue_id', 'position']);
        });

        // Reading and listening share almost all metadata; one table, one discriminator.
        Schema::create('passages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('language_id')->constrained()->cascadeOnDelete();
            $table->string('modality', 16);                 // reading|listening
            $table->string('title');
            $table->longText('body')->nullable()->comment('reading text, or listening transcript');
            $table->foreignId('cefr_level_id')->nullable()->constrained('cefr_levels')->nullOnDelete();
            $table->foreignId('topic_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('word_count')->nullable();
            // Readability / speech-rate signals used by the difficulty engine.
            $table->decimal('readability_score', 6, 2)->nullable();
            $table->unsignedSmallInteger('words_per_minute')->nullable();
            $table->foreignId('audio_media_asset_id')->nullable();
            $table->string('genre', 48)->nullable();
            $table->string('generation_method', 32)->default('authored');
            $table->string('copyright_status', 32)->default('owned');
            $table->foreignId('source_document_id')->nullable();
            $table->unsignedInteger('source_page')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['modality', 'cefr_level_id']);
        });

        // Sentence-level segmentation drives replay-by-sentence and dictation.
        Schema::create('passage_segments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('passage_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->text('text');
            $table->unsignedInteger('audio_start_ms')->nullable();
            $table->unsignedInteger('audio_end_ms')->nullable();
            $table->timestamps();

            $table->unique(['passage_id', 'position']);
        });

        // Productive-skill prompts (speaking and writing) share scoring shape.
        Schema::create('production_prompts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('language_id')->constrained()->cascadeOnDelete();
            $table->string('modality', 16);                 // speaking|writing
            $table->string('task_type', 48);                // sentence|paragraph|email|essay|monologue|roleplay
            $table->string('title');
            $table->text('prompt');
            $table->text('guidance')->nullable();
            $table->foreignId('cefr_level_id')->nullable()->constrained('cefr_levels')->nullOnDelete();
            $table->foreignId('topic_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('min_words')->nullable();
            $table->unsignedSmallInteger('max_words')->nullable();
            $table->unsignedSmallInteger('prep_seconds')->nullable();
            $table->unsignedSmallInteger('response_seconds')->nullable();
            // Criteria the AI grader must report against, e.g. IELTS writing bands.
            $table->json('rubric')->nullable();
            $table->string('generation_method', 32)->default('authored');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['modality', 'cefr_level_id']);
        });

        // Conversation scenarios for AI roleplay mode (spec 26).
        Schema::create('conversation_scenarios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('language_id')->constrained()->cascadeOnDelete();
            $table->string('slug')->unique();
            $table->string('title');
            $table->string('setting', 64);                  // restaurant|airport|job_interview|...
            $table->text('situation');
            $table->text('learner_role');
            $table->text('ai_role');
            $table->foreignId('character_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('cefr_level_id')->nullable()->constrained('cefr_levels')->nullOnDelete();
            $table->json('objectives')->nullable()->comment('communicative goals the learner must achieve');
            $table->unsignedSmallInteger('target_turns')->default(10);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_scenarios');
        Schema::dropIfExists('production_prompts');
        Schema::dropIfExists('passage_segments');
        Schema::dropIfExists('passages');
        Schema::dropIfExists('dialogue_turns');
        Schema::dropIfExists('dialogues');
        Schema::dropIfExists('characters');
    }
};
