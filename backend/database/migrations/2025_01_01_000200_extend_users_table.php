<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 32)->default('learner')->after('email'); // learner|admin|editor|reviewer
            $table->string('status', 32)->default('active')->after('role'); // active|suspended|deleted
            $table->string('avatar_path')->nullable()->after('status');
            $table->string('locale', 12)->default('en')->after('avatar_path');
            $table->string('timezone', 64)->default('UTC')->after('locale');
            $table->timestamp('last_active_at')->nullable()->after('timezone');
            $table->softDeletes();

            $table->index('role');
            $table->index('status');
            $table->index('last_active_at');
        });

        Schema::create('user_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('native_language_id')->nullable()->constrained('languages')->nullOnDelete();
            $table->foreignId('target_language_id')->nullable()->constrained('languages')->nullOnDelete();
            $table->char('country_code', 2)->nullable();
            $table->date('date_of_birth')->nullable();
            // Primary reason for learning; drives curriculum weighting and exam module unlock.
            $table->string('learning_objective', 64)->nullable();
            // Safe personalisation signals only (spec 53) - never sensitive categories.
            $table->string('profession')->nullable();
            $table->json('interests')->nullable();
            $table->json('favourite_topics')->nullable();
            $table->timestamps();

            $table->index('target_language_id');
            $table->index('learning_objective');
        });

        Schema::create('user_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('daily_target_minutes')->default(15);
            $table->unsignedSmallInteger('weekly_goal_minutes')->default(105);
            $table->time('preferred_study_time')->nullable();
            $table->string('theme', 16)->default('dark');
            $table->boolean('notifications_email')->default(true);
            $table->boolean('notifications_push')->default(true);
            $table->boolean('reminder_enabled')->default(true);

            // Privacy / consent (spec 45). Raw audio retention is separable from
            // the derived, anonymised pronunciation statistics.
            $table->boolean('speech_consent_given')->default(false);
            $table->timestamp('speech_consent_at')->nullable();
            $table->unsignedSmallInteger('speech_retention_days')->default(90);
            $table->boolean('allow_speech_for_model_improvement')->default(false);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_settings');
        Schema::dropIfExists('user_profiles');

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['role']);
            $table->dropIndex(['status']);
            $table->dropIndex(['last_active_at']);
            $table->dropSoftDeletes();
            $table->dropColumn([
                'role', 'status', 'avatar_path', 'locale', 'timezone', 'last_active_at',
            ]);
        });
    }
};
