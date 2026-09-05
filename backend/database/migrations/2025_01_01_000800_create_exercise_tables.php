<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exercise_templates', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();             // multiple_choice|fill_blank|reorder|...
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('block_type', 48);
            $table->json('skill_codes')->nullable()->comment('skills this template can assess');
            $table->boolean('is_productive')->default(false)->comment('needs AI grading, not key matching');
            $table->boolean('supports_audio')->default(false);
            $table->boolean('supports_image')->default(false);
            // JSON Schema the generator must satisfy and the validator checks against.
            $table->json('payload_schema')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('exercises', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exercise_template_id')->constrained()->cascadeOnDelete();
            $table->foreignId('language_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lesson_id')->nullable()->constrained()->nullOnDelete()
                ->comment('null for item-bank//generated practice not tied to a lesson');
            $table->foreignId('skill_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('subskill_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('cefr_level_id')->nullable()->constrained('cefr_levels')->nullOnDelete();

            $table->text('stem')->comment('the question/prompt shown to the learner');
            $table->text('instructions')->nullable();
            $table->json('payload')->nullable()->comment('template-specific presentation data only');

            // IRT parameters. difficulty is on the same logit scale as learner ability
            // so the difficulty engine can target 70-85% success probability.
            $table->decimal('difficulty', 6, 3)->default(0.000);
            $table->decimal('discrimination', 6, 3)->default(1.000);
            $table->decimal('guessing', 4, 3)->default(0.000);
            // Live calibration from real attempts.
            $table->unsignedInteger('attempt_count')->default(0);
            $table->unsignedInteger('correct_count')->default(0);
            $table->decimal('avg_response_ms', 10, 2)->nullable();

            $table->foreignId('media_asset_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('audio_media_asset_id')->nullable()->constrained('media_assets')->nullOnDelete();
            $table->foreignId('passage_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('dialogue_id')->nullable()->constrained()->nullOnDelete();

            $table->string('status', 32)->default('draft'); // draft|review|approved|published|rejected
            $table->decimal('validation_score', 4, 3)->nullable();
            $table->boolean('is_placement_eligible')->default(false);
            $table->boolean('is_exam_eligible')->default(false);

            $table->string('generation_method', 32)->default('authored');
            $table->string('copyright_status', 32)->default('owned');
            $table->foreignId('source_document_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('source_page')->nullable();
            $table->string('source_reference')->nullable();
            $table->foreignId('ai_generation_id')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'cefr_level_id']);
            $table->index(['skill_id', 'difficulty']);
            $table->index('is_placement_eligible');
            $table->index('lesson_id');
        });

        Schema::create('exercise_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exercise_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->text('text')->nullable();
            $table->foreignId('media_asset_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_correct')->default(false);
            // Why this wrong option is tempting - drives targeted feedback.
            $table->text('distractor_rationale')->nullable();
            $table->timestamps();

            $table->unique(['exercise_id', 'position']);
        });

        // Accepted answers. Multiple rows allow spelling/formatting variants.
        Schema::create('exercise_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exercise_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('blank_index')->default(0)->comment('for multi-blank items');
            $table->text('value');
            $table->string('match_mode', 24)->default('exact'); // exact|normalised|regex|fuzzy|semantic
            $table->boolean('is_primary')->default(true);
            $table->decimal('credit', 4, 3)->default(1.000)->comment('partial credit for near-misses');
            $table->timestamps();

            $table->index(['exercise_id', 'blank_index']);
        });

        Schema::create('exercise_hints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exercise_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('level')->default(1)->comment('progressive disclosure order');
            $table->text('text');
            $table->timestamps();

            $table->unique(['exercise_id', 'level']);
        });

        Schema::create('exercise_explanations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exercise_id')->constrained()->cascadeOnDelete();
            $table->foreignId('language_id')->constrained()->comment('explanation language; L1 allowed at low levels');
            $table->foreignId('cefr_level_id')->nullable()->constrained('cefr_levels')->nullOnDelete();
            $table->text('text');
            $table->string('generation_method', 32)->default('authored');
            $table->timestamps();

            $table->index(['exercise_id', 'language_id']);
        });

        // Which knowledge-graph nodes an exercise actually tests. Mastery updates
        // fan out through this table, so it is the join the learning engine leans on.
        Schema::create('exercise_concepts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exercise_id')->constrained()->cascadeOnDelete();
            $table->foreignId('concept_id')->constrained()->cascadeOnDelete();
            $table->decimal('weight', 4, 3)->default(1.000);
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->unique(['exercise_id', 'concept_id'], 'exercise_concepts_unique');
            $table->index('concept_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exercise_concepts');
        Schema::dropIfExists('exercise_explanations');
        Schema::dropIfExists('exercise_hints');
        Schema::dropIfExists('exercise_answers');
        Schema::dropIfExists('exercise_options');
        Schema::dropIfExists('exercises');
        Schema::dropIfExists('exercise_templates');
    }
};
