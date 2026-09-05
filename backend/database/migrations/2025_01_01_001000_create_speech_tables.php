<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('speech_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exercise_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('production_prompt_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('pronunciation_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('learning_session_id')->nullable()->constrained()->nullOnDelete();

            // Raw audio is nullable and separately deletable: the scores and error rows
            // below survive deletion of the recording (spec 45).
            $table->foreignId('media_asset_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('audio_deleted')->default(false);
            $table->timestamp('audio_delete_after')->nullable();

            $table->text('expected_text')->nullable();
            $table->longText('transcript')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();

            $table->string('status', 24)->default('pending'); // pending|processing|scored|failed
            $table->text('error')->nullable();

            // 0-100 scores. Null until the corresponding analyser has run.
            $table->decimal('overall_score', 5, 2)->nullable();
            $table->decimal('pronunciation_score', 5, 2)->nullable();
            $table->decimal('fluency_score', 5, 2)->nullable();
            $table->decimal('grammar_score', 5, 2)->nullable();
            $table->decimal('vocabulary_score', 5, 2)->nullable();
            $table->decimal('completeness_score', 5, 2)->nullable();

            // Fluency signals.
            $table->decimal('speech_rate_wpm', 6, 2)->nullable();
            $table->unsignedSmallInteger('pause_count')->nullable();
            $table->unsignedInteger('total_pause_ms')->nullable();
            $table->unsignedSmallInteger('filler_count')->nullable();

            $table->json('feedback')->nullable();
            $table->string('stt_provider', 48)->nullable();
            $table->string('aligner', 48)->nullable();
            $table->timestamp('scored_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index('status');
            $table->index('audio_delete_after');
        });

        // Per-word alignment output. Confidence and timings come from forced alignment,
        // not from raw speech-to-text alone (spec 21).
        Schema::create('speech_words', function (Blueprint $table) {
            $table->id();
            $table->foreignId('speech_attempt_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('expected_word')->nullable();
            $table->string('spoken_word')->nullable();
            $table->unsignedInteger('start_ms')->nullable();
            $table->unsignedInteger('end_ms')->nullable();
            $table->decimal('confidence', 4, 3)->nullable();
            $table->decimal('accuracy_score', 5, 2)->nullable();
            $table->string('outcome', 24)->default('correct'); // correct|mispronounced|omitted|inserted|substituted
            $table->boolean('stress_correct')->nullable();
            $table->timestamps();

            $table->unique(['speech_attempt_id', 'position'], 'speech_words_attempt_pos_unique');
            $table->index('outcome');
        });

        Schema::create('speech_phonemes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('speech_word_id')->constrained()->cascadeOnDelete();
            $table->foreignId('expected_phoneme_id')->nullable()->constrained('phonemes')->nullOnDelete();
            $table->foreignId('actual_phoneme_id')->nullable()->constrained('phonemes')->nullOnDelete();
            $table->unsignedSmallInteger('position');
            $table->unsignedInteger('start_ms')->nullable();
            $table->unsignedInteger('end_ms')->nullable();
            $table->decimal('accuracy_score', 5, 2)->nullable();
            $table->boolean('is_error')->default(false);
            $table->timestamps();

            $table->index(['speech_word_id', 'position']);
            $table->index('is_error');
        });

        // Rolling per-phoneme profile. This is the anonymisable statistic that outlives
        // the raw recording and drives automatic drill generation.
        Schema::create('pronunciation_errors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('phoneme_id')->constrained()->cascadeOnDelete();
            $table->foreignId('substituted_phoneme_id')->nullable()->constrained('phonemes')->nullOnDelete();
            $table->unsignedInteger('occurrence_count')->default(1);
            $table->unsignedInteger('attempt_count')->default(1);
            $table->decimal('error_rate', 4, 3)->default(0.000);
            $table->decimal('recent_error_rate', 4, 3)->default(0.000)->comment('windowed, so improvement shows');
            $table->json('example_words')->nullable();
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'phoneme_id', 'substituted_phoneme_id'], 'pron_errors_user_phoneme_unique');
            $table->index(['user_id', 'error_rate']);
        });

        // AI roleplay conversations (spec 26).
        Schema::create('conversation_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_scenario_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('learning_session_id')->nullable()->constrained()->nullOnDelete();
            $table->string('mode', 24)->default('voice'); // voice|text|mixed
            $table->string('status', 24)->default('active');
            $table->unsignedSmallInteger('turn_count')->default(0);
            $table->unsignedInteger('duration_seconds')->default(0);
            $table->json('objectives_met')->nullable();
            // Debrief shown only at the end, never mid-conversation.
            $table->json('summary')->nullable();
            $table->decimal('overall_score', 5, 2)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });

        Schema::create('conversation_turns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_session_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('speaker', 16); // learner|ai
            $table->text('text');
            $table->foreignId('speech_attempt_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('audio_media_asset_id')->nullable()->constrained('media_assets')->nullOnDelete();
            // Observations recorded silently; surfaced in the debrief, not as live corrections.
            $table->json('observed_errors')->nullable();
            $table->boolean('blocked_communication')->default(false);
            $table->timestamps();

            $table->unique(['conversation_session_id', 'position'], 'conversation_turns_pos_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_turns');
        Schema::dropIfExists('conversation_sessions');
        Schema::dropIfExists('pronunciation_errors');
        Schema::dropIfExists('speech_phonemes');
        Schema::dropIfExists('speech_words');
        Schema::dropIfExists('speech_attempts');
    }
};
