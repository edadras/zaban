<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Live-room presentation state, chat, and whiteboard strokes.
 *
 * `stage` on the session is the single shared cursor: which mode is up
 * (material vs whiteboard), which PDF page, and where the video is. Chat is
 * its own table so a busy class does not rewrite a JSON blob on every line.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('class_sessions', function (Blueprint $table) {
            $table->json('stage')->nullable()->after('status');
        });

        Schema::create('class_chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_session_id')->constrained('class_sessions')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('body', 1000);
            $table->timestamps();

            $table->index(['class_session_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('class_chat_messages');

        Schema::table('class_sessions', function (Blueprint $table) {
            $table->dropColumn('stage');
        });
    }
};
