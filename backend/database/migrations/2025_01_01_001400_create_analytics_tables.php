<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name', 96);
            $table->string('category', 48)->nullable();
            $table->nullableMorphs('subject');
            $table->json('properties')->nullable();
            $table->string('platform', 24)->nullable();       // web|android|ios
            $table->string('app_version', 32)->nullable();
            $table->string('session_uuid', 64)->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['user_id', 'occurred_at']);
            $table->index(['name', 'occurred_at']);
        });

        // One row per learner per day; backs streaks, goals and the progress charts.
        Schema::create('daily_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->unsignedInteger('study_seconds')->default(0);
            $table->unsignedSmallInteger('sessions_completed')->default(0);
            $table->unsignedSmallInteger('lessons_completed')->default(0);
            $table->unsignedSmallInteger('exercises_attempted')->default(0);
            $table->unsignedSmallInteger('exercises_correct')->default(0);
            $table->unsignedSmallInteger('reviews_completed')->default(0);
            $table->unsignedSmallInteger('new_concepts')->default(0);
            $table->unsignedSmallInteger('concepts_mastered')->default(0);
            $table->unsignedSmallInteger('speaking_seconds')->default(0);
            $table->unsignedInteger('xp_earned')->default(0);
            $table->boolean('goal_met')->default(false);
            $table->timestamps();

            $table->unique(['user_id', 'date']);
            $table->index('date');
        });

        // Periodic snapshot of per-skill ability, so trend lines survive recalibration.
        Schema::create('skill_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('skill_id')->constrained()->cascadeOnDelete();
            $table->date('snapshot_date');
            $table->decimal('ability', 6, 3);
            $table->decimal('ability_se', 6, 3)->nullable();
            $table->foreignId('cefr_level_id')->nullable()->constrained('cefr_levels')->nullOnDelete();
            $table->decimal('mastery_score', 5, 4)->nullable();
            $table->unsignedInteger('concepts_tracked')->default(0);
            $table->unsignedInteger('concepts_mastered')->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'skill_id', 'snapshot_date'], 'skill_snapshots_unique');
        });

        Schema::create('achievements', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('icon', 64)->nullable();
            $table->string('category', 32)->default('progress'); // progress|streak|mastery|speaking|exam
            $table->unsignedInteger('xp_reward')->default(0);
            // Machine-checkable unlock condition, evaluated by the achievement service.
            $table->json('criteria');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('user_achievements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('achievement_id')->constrained()->cascadeOnDelete();
            $table->timestamp('unlocked_at');
            $table->timestamps();

            $table->unique(['user_id', 'achievement_id']);
        });

        // Append-only XP ledger; learner_profiles.xp is the derived running total.
        Schema::create('xp_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->integer('amount');
            $table->string('reason', 64);
            $table->nullableMorphs('source');
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });

        // Admin action audit trail (spec 46).
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 96);
            $table->nullableMorphs('auditable');
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index('action');
        });

        // Fulfils the data-export / erasure requests in spec 45.
        Schema::create('privacy_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 24);                        // export|delete_audio|delete_account
            $table->string('status', 24)->default('pending');  // pending|processing|completed|failed
            $table->string('export_path')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('privacy_requests');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('xp_transactions');
        Schema::dropIfExists('user_achievements');
        Schema::dropIfExists('achievements');
        Schema::dropIfExists('skill_snapshots');
        Schema::dropIfExists('daily_progress');
        Schema::dropIfExists('user_events');
    }
};
