<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('speech_coach_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 24)->default('active');
            $table->unsignedSmallInteger('turn_count')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });

        Schema::create('speech_coach_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('speech_coach_session_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('role', 16); // learner|coach
            $table->text('text');
            $table->text('text_fa')->nullable();
            $table->text('corrected_text')->nullable();
            $table->json('coaching')->nullable();
            $table->foreignId('speech_attempt_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique(['speech_coach_session_id', 'position'], 'speech_coach_messages_pos_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('speech_coach_messages');
        Schema::dropIfExists('speech_coach_sessions');
    }
};
