<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('placement_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('language_id')->constrained()->cascadeOnDelete();
            $table->string('status', 24)->default('in_progress'); // in_progress|completed|abandoned

            // Running global estimate; per-skill estimates live in placement_skill_states.
            $table->decimal('ability', 6, 3)->default(0.000);
            $table->decimal('ability_se', 6, 3)->default(1.500);
            $table->unsignedSmallInteger('items_administered')->default(0);
            $table->unsignedSmallInteger('max_items')->default(40);

            $table->foreignId('result_cefr_level_id')->nullable()->constrained('cefr_levels')->nullOnDelete();
            $table->decimal('result_confidence', 4, 3)->nullable();
            $table->string('stop_reason', 32)->nullable(); // precision_reached|max_items|abandoned

            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });

        // Per-dimension CAT state; each skill stops independently once its SE is low enough.
        Schema::create('placement_skill_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('placement_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('skill_id')->constrained()->cascadeOnDelete();
            $table->decimal('ability', 6, 3)->default(0.000);
            $table->decimal('ability_se', 6, 3)->default(1.500);
            $table->unsignedSmallInteger('items_administered')->default(0);
            $table->unsignedSmallInteger('min_items')->default(4);
            $table->unsignedSmallInteger('max_items')->default(12);
            $table->decimal('target_se', 4, 3)->default(0.320);
            $table->boolean('is_complete')->default(false);
            $table->foreignId('result_cefr_level_id')->nullable()->constrained('cefr_levels')->nullOnDelete();
            $table->decimal('confidence', 4, 3)->nullable();
            $table->timestamps();

            $table->unique(['placement_session_id', 'skill_id'], 'placement_skill_unique');
        });

        Schema::create('placement_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('placement_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exercise_id')->constrained()->cascadeOnDelete();
            $table->foreignId('skill_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sequence');

            $table->json('response')->nullable();
            $table->boolean('is_correct')->nullable();
            $table->decimal('score', 5, 4)->nullable();
            $table->foreignId('speech_attempt_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('response_ms')->nullable();

            // CAT bookkeeping: state before the item and the update it produced.
            $table->decimal('ability_before', 6, 3);
            $table->decimal('ability_after', 6, 3)->nullable();
            $table->decimal('se_before', 6, 3);
            $table->decimal('se_after', 6, 3)->nullable();
            $table->decimal('item_information', 8, 5)->nullable()->comment('Fisher information, why this item was chosen');

            $table->timestamp('presented_at');
            $table->timestamp('answered_at')->nullable();
            $table->timestamps();

            $table->unique(['placement_session_id', 'sequence'], 'placement_responses_seq_unique');
            $table->index('exercise_id');
        });

        // ---------- Exam preparation layer (spec 31, 32) ----------

        Schema::create('exam_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('language_id')->constrained()->cascadeOnDelete();
            $table->string('code', 32)->unique();             // ielts_academic|toefl_ibt|cambridge_b2|pte_academic
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('score_type', 24);                 // band|scaled|points
            $table->decimal('score_min', 6, 2);
            $table->decimal('score_max', 6, 2);
            $table->decimal('score_step', 4, 2)->default(0.50);
            $table->unsignedSmallInteger('total_minutes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Maps an exam score onto CEFR so the general curriculum and exam prep
        // can share one knowledge graph.
        Schema::create('exam_score_bands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_type_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cefr_level_id')->constrained('cefr_levels')->cascadeOnDelete();
            $table->decimal('score_from', 6, 2);
            $table->decimal('score_to', 6, 2);
            $table->timestamps();

            $table->index(['exam_type_id', 'score_from', 'score_to'], 'exam_bands_lookup_idx');
        });

        Schema::create('exam_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_type_id')->constrained()->cascadeOnDelete();
            $table->foreignId('skill_id')->constrained()->cascadeOnDelete();
            $table->string('code', 48);
            $table->string('name');
            $table->unsignedSmallInteger('position');
            $table->unsignedSmallInteger('duration_minutes');
            $table->unsignedSmallInteger('question_count')->nullable();
            $table->json('scoring_criteria')->nullable();
            $table->timestamps();

            $table->unique(['exam_type_id', 'code']);
        });

        Schema::create('exam_task_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_section_id')->constrained()->cascadeOnDelete();
            $table->string('code', 64);                       // matching_headings|map_labelling|part_2_long_turn
            $table->string('name');
            $table->text('description')->nullable();
            $table->foreignId('exercise_template_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('typical_count')->nullable();
            $table->timestamps();

            $table->unique(['exam_section_id', 'code']);
        });

        Schema::create('exam_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_task_type_id')->constrained()->cascadeOnDelete();
            $table->foreignId('passage_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('production_prompt_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->text('instructions')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->unsignedSmallInteger('time_limit_seconds')->nullable();
            $table->string('status', 32)->default('draft');
            $table->string('generation_method', 32)->default('authored');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('exam_task_exercise', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exercise_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
            $table->unique(['exam_task_id', 'exercise_id'], 'exam_task_exercise_unique');
        });

        Schema::create('exam_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exam_type_id')->constrained()->cascadeOnDelete();
            $table->string('mode', 24)->default('practice');  // practice|mock|section
            $table->string('status', 24)->default('in_progress');
            $table->decimal('estimated_score', 6, 2)->nullable();
            $table->foreignId('estimated_cefr_level_id')->nullable()->constrained('cefr_levels')->nullOnDelete();
            // Always true for AI-produced scores; the UI must label them as estimates.
            $table->boolean('is_ai_estimated')->default(true);
            $table->unsignedInteger('duration_seconds')->default(0);
            $table->json('time_management')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'exam_type_id']);
        });

        Schema::create('exam_section_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_attempt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exam_section_id')->constrained()->cascadeOnDelete();
            $table->string('status', 24)->default('pending');
            $table->decimal('raw_score', 6, 2)->nullable();
            $table->decimal('estimated_score', 6, 2)->nullable();
            $table->unsignedInteger('duration_seconds')->default(0);
            $table->boolean('ran_out_of_time')->default(false);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['exam_attempt_id', 'exam_section_id'], 'exam_section_attempt_unique');
        });

        // Criterion-level scores, e.g. IELTS fluency / lexis / grammar / pronunciation.
        Schema::create('exam_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_attempt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exam_section_attempt_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('criterion', 64);
            $table->decimal('score', 6, 2);
            $table->text('rationale')->nullable();
            $table->json('evidence')->nullable();
            $table->timestamps();

            $table->index(['exam_attempt_id', 'criterion']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_scores');
        Schema::dropIfExists('exam_section_attempts');
        Schema::dropIfExists('exam_attempts');
        Schema::dropIfExists('exam_task_exercise');
        Schema::dropIfExists('exam_tasks');
        Schema::dropIfExists('exam_task_types');
        Schema::dropIfExists('exam_sections');
        Schema::dropIfExists('exam_score_bands');
        Schema::dropIfExists('exam_types');
        Schema::dropIfExists('placement_responses');
        Schema::dropIfExists('placement_skill_states');
        Schema::dropIfExists('placement_sessions');
    }
};
