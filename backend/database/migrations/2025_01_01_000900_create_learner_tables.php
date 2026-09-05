<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('learner_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('language_id')->constrained()->comment('target language');
            $table->foreignId('current_cefr_level_id')->nullable()->constrained('cefr_levels')->nullOnDelete();
            $table->foreignId('active_course_version_id')->nullable()->constrained('course_versions')->nullOnDelete();

            // Overall ability on the shared logit scale, with its uncertainty.
            $table->decimal('ability', 6, 3)->default(0.000);
            $table->decimal('ability_se', 6, 3)->default(1.500);

            $table->string('placement_status', 32)->default('not_started'); // not_started|in_progress|completed|skipped
            $table->timestamp('placed_at')->nullable();

            // Gamification / engagement counters (spec 33).
            $table->unsignedInteger('xp')->default(0);
            $table->unsignedSmallInteger('streak_days')->default(0);
            $table->unsignedSmallInteger('longest_streak_days')->default(0);
            $table->date('last_study_date')->nullable();
            $table->unsignedInteger('total_study_minutes')->default(0);
            $table->decimal('mastery_score', 5, 4)->default(0.0000)->comment('aggregate across active concepts');

            // Recent-frustration signal feeding difficulty selection (spec 13, 52).
            $table->decimal('frustration_index', 4, 3)->default(0.000);
            $table->timestamp('last_session_at')->nullable();

            $table->timestamps();

            $table->index('placement_status');
            $table->index('last_study_date');
        });

        // Per-skill ability, so a learner can be B2 reading and A2 speaking.
        Schema::create('learner_skill_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('skill_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cefr_level_id')->nullable()->constrained('cefr_levels')->nullOnDelete();
            $table->decimal('ability', 6, 3)->default(0.000);
            $table->decimal('ability_se', 6, 3)->default(1.500);
            $table->decimal('confidence', 4, 3)->default(0.000);
            $table->unsignedInteger('attempt_count')->default(0);
            $table->timestamp('last_assessed_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'skill_id']);
        });

        // The learner knowledge graph (spec 12) fused with spaced repetition (spec 14).
        // Mastery only rises through repeated successful retrieval spread over time -
        // a single correct answer can never push a concept past 'introduced'.
        Schema::create('learner_concepts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('concept_id')->constrained()->cascadeOnDelete();

            $table->decimal('mastery_score', 4, 3)->default(0.000); // 0.00 unknown .. 0.95 mastered
            $table->decimal('confidence', 4, 3)->default(0.000);
            $table->unsignedInteger('exposure_count')->default(0);
            $table->unsignedInteger('correct_count')->default(0);
            $table->unsignedInteger('incorrect_count')->default(0);
            $table->unsignedInteger('hint_count')->default(0);
            $table->unsignedSmallInteger('consecutive_correct')->default(0);
            $table->unsignedInteger('avg_response_ms')->nullable();
            // Success rate bucketed by item difficulty, so we can tell "knows it" from
            // "guesses easy ones".
            $table->json('difficulty_performance')->nullable();

            // Spaced repetition state (SM-2 style, personalised per learner).
            $table->decimal('memory_strength', 6, 3)->default(0.000);
            $table->decimal('ease_factor', 4, 3)->default(2.500);
            $table->unsignedInteger('interval_days')->default(0);
            $table->unsignedSmallInteger('repetition_number')->default(0);
            $table->timestamp('next_review_at')->nullable();
            // Cached forgetting estimate; recomputed on write so due-queue reads stay cheap.
            $table->decimal('decay_score', 4, 3)->default(0.000);

            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('mastered_at')->nullable();

            $table->timestamps();

            $table->unique(['user_id', 'concept_id']);
            // Drives the due-review queue and the weakness scan.
            $table->index(['user_id', 'next_review_at']);
            $table->index(['user_id', 'mastery_score']);
        });

        // Every meaningful mistake is remembered and reused in later lessons (spec 22).
        Schema::create('learner_errors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('concept_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('skill_id')->nullable()->constrained()->nullOnDelete();
            // vocabulary_confusion|grammar|pronunciation|listening|spelling|word_order|
            // article|preposition|collocation|register
            $table->string('error_type', 48);
            $table->string('error_subtype', 64)->nullable();
            $table->text('input')->nullable()->comment('what the learner produced');
            $table->text('expected')->nullable();
            $table->text('note')->nullable();
            $table->unsignedTinyInteger('severity')->default(2)->comment('1 minor .. 5 blocks communication');
            $table->decimal('confidence', 4, 3)->default(1.000)->comment('certainty this really is an error');
            $table->unsignedInteger('occurrence_count')->default(1);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('resolved_at')->nullable();
            $table->unsignedSmallInteger('remediation_count')->default(0);
            $table->timestamps();

            $table->index(['user_id', 'error_type']);
            $table->index(['user_id', 'resolved_at']);
            $table->unique(['user_id', 'concept_id', 'error_type', 'error_subtype'], 'learner_errors_dedupe');
        });

        Schema::create('learner_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('learner_concept_id')->constrained()->cascadeOnDelete();
            $table->timestamp('scheduled_for');
            $table->string('status', 24)->default('due'); // due|completed|skipped|expired
            $table->string('trigger', 32)->default('spaced'); // spaced|error_driven|prerequisite|manual
            $table->timestamp('completed_at')->nullable();
            $table->boolean('was_successful')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status', 'scheduled_for'], 'learner_reviews_queue_idx');
        });

        // Immutable audit of every review outcome; learner_concepts holds only current state.
        Schema::create('review_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('concept_id')->constrained()->cascadeOnDelete();
            $table->boolean('was_successful');
            $table->decimal('quality', 3, 2)->comment('0-5 recall quality feeding the interval update');
            $table->decimal('mastery_before', 4, 3);
            $table->decimal('mastery_after', 4, 3);
            $table->unsignedInteger('interval_days_before');
            $table->unsignedInteger('interval_days_after');
            $table->unsignedInteger('response_ms')->nullable();
            $table->timestamp('reviewed_at');
            $table->timestamps();

            $table->index(['user_id', 'concept_id', 'reviewed_at'], 'review_history_lookup_idx');
        });

        Schema::create('learning_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_version_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 24)->default('active'); // active|completed|abandoned
            $table->string('kind', 32)->default('daily'); // daily|practice|remediation|exam_prep|free
            // The composition the engine chose, kept for tuning the weighting model.
            $table->json('composition')->nullable();
            $table->unsignedSmallInteger('planned_minutes')->default(15);
            $table->unsignedInteger('actual_seconds')->default(0);
            $table->unsignedSmallInteger('activities_planned')->default(0);
            $table->unsignedSmallInteger('activities_completed')->default(0);
            $table->unsignedInteger('xp_earned')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'created_at']);
        });

        // Ordered blocks the backend hands to Flutter. The client never composes these.
        Schema::create('session_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('learning_session_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('activity_type', 48); // review|lesson_block|exercise|speaking|pronunciation|conversation|challenge
            $table->nullableMorphs('subject');   // exercise|lesson|lesson_block|conversation_scenario
            $table->foreignId('concept_id')->nullable()->constrained()->nullOnDelete();
            // Why the engine picked this, for explainability and offline tuning.
            $table->json('selection_reason')->nullable();
            $table->decimal('priority_score', 8, 4)->nullable();
            $table->decimal('predicted_success', 4, 3)->nullable();
            $table->string('status', 24)->default('pending'); // pending|in_progress|completed|skipped
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['learning_session_id', 'position'], 'session_activities_pos_unique');
        });

        Schema::create('lesson_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lesson_id')->constrained()->cascadeOnDelete();
            $table->foreignId('learning_session_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 24)->default('in_progress');
            $table->unsignedSmallInteger('blocks_total')->default(0);
            $table->unsignedSmallInteger('blocks_completed')->default(0);
            $table->decimal('score', 5, 4)->nullable();
            $table->unsignedInteger('duration_seconds')->default(0);
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'lesson_id']);
        });

        Schema::create('exercise_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exercise_id')->constrained()->cascadeOnDelete();
            $table->foreignId('learning_session_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('lesson_attempt_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('session_activity_id')->nullable()->constrained()->nullOnDelete();

            $table->json('response')->nullable();
            $table->boolean('is_correct');
            $table->decimal('score', 5, 4)->default(0.0000)->comment('partial credit aware');
            $table->unsignedSmallInteger('hints_used')->default(0);
            $table->unsignedSmallInteger('attempt_number')->default(1);
            $table->unsignedInteger('response_ms')->nullable();
            // Ability estimate at answer time, so item difficulty can be recalibrated later.
            $table->decimal('ability_at_attempt', 6, 3)->nullable();
            $table->decimal('predicted_success', 4, 3)->nullable();
            $table->json('feedback')->nullable();
            $table->timestamp('answered_at');
            $table->timestamps();

            $table->index(['user_id', 'exercise_id']);
            $table->index(['user_id', 'answered_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exercise_attempts');
        Schema::dropIfExists('lesson_attempts');
        Schema::dropIfExists('session_activities');
        Schema::dropIfExists('learning_sessions');
        Schema::dropIfExists('review_history');
        Schema::dropIfExists('learner_reviews');
        Schema::dropIfExists('learner_errors');
        Schema::dropIfExists('learner_concepts');
        Schema::dropIfExists('learner_skill_states');
        Schema::dropIfExists('learner_profiles');
    }
};
