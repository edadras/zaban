<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Homework.
 *
 * Several kinds, because "homework" in a language class is not one thing: a
 * paragraph to write, a passage to read aloud, twenty items from the course,
 * a photograph of a page done on paper, or simply "do your twenty minutes".
 * Each is set the same way and each comes back marked, but only some of them
 * can be marked by a machine, and the schema says which.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_group_id')->constrained()->cascadeOnDelete();

            // Homework set in a lesson keeps its lesson, so "what did we do
            // that day" and "what were we asked to do" stay together.
            $table->foreignId('class_session_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();

            // writing | speaking | exercises | upload | practice | reading
            $table->string('kind', 16);
            $table->string('title', 200);
            $table->longText('brief')->nullable();

            // What the work is about, when it is drawn from the course.
            $table->foreignId('lesson_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('cefr_level_id')->nullable()->constrained('cefr_levels')->nullOnDelete();

            $table->timestamp('due_at')->nullable();
            $table->timestamp('published_at')->nullable();

            $table->unsignedSmallInteger('points')->default(100);
            $table->boolean('allow_late')->default(true);

            /*
             * Whether the assistant's mark is released as it stands.
             *
             * Off by default and deliberately so: a learner must never find
             * that the only judgement on their work is one no person looked
             * at. On, it is for twenty multiple-choice items where the marking
             * is arithmetic.
             */
            $table->boolean('auto_release')->default(false);

            // Per-kind settings: word counts, minutes of practice, the passage
            // to read. One column because they share nothing.
            $table->json('settings')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['class_group_id', 'due_at']);
            $table->index(['class_group_id', 'published_at']);
        });

        /*
         * The questions, for the kinds that have questions.
         *
         * An item either points at an exercise in the course - and then it
         * brings its own wording, options and answer - or carries its own.
         */
        Schema::create('assignment_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assignment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exercise_id')->nullable()->constrained()->nullOnDelete();
            $table->text('prompt')->nullable();
            $table->json('options')->nullable();
            $table->json('correct_options')->nullable();
            $table->unsignedSmallInteger('points')->default(1);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['assignment_id', 'position']);
        });

        Schema::create('assignment_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assignment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // assigned | draft | submitted | marking | marked | returned
            $table->string('status', 16)->default('assigned');

            $table->longText('body')->nullable();

            /*
             * The existing machinery, reused rather than copied.
             *
             * A written piece of homework is a writing attempt and a spoken
             * one is a speech attempt, so both are marked by the analysers the
             * product already has instead of a second marking path that would
             * drift away from them.
             */
            $table->foreignId('writing_attempt_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('speech_attempt_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamp('submitted_at')->nullable();
            $table->boolean('is_late')->default(false);

            // What the machine thought, kept apart from what the coach decided,
            // so a mark can always be traced to whoever made it.
            $table->decimal('ai_score', 5, 2)->nullable();
            $table->json('ai_feedback')->nullable();
            $table->string('ai_model', 48)->nullable();
            $table->timestamp('ai_marked_at')->nullable();
            $table->text('ai_error')->nullable();

            $table->decimal('score', 5, 2)->nullable();
            $table->longText('feedback')->nullable();
            $table->foreignId('marked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('marked_at')->nullable();
            $table->timestamp('returned_at')->nullable();

            $table->timestamps();

            $table->unique(['assignment_id', 'user_id']);
            $table->index(['user_id', 'status']);
        });

        Schema::create('assignment_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assignment_submission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assignment_item_id')->constrained()->cascadeOnDelete();
            $table->text('body')->nullable();
            $table->json('selected_options')->nullable();
            $table->boolean('is_correct')->nullable();
            $table->decimal('score', 5, 2)->nullable();
            $table->timestamps();

            $table->unique(['assignment_submission_id', 'assignment_item_id'], 'submission_item_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assignment_responses');
        Schema::dropIfExists('assignment_submissions');
        Schema::dropIfExists('assignment_items');
        Schema::dropIfExists('assignments');
    }
};
