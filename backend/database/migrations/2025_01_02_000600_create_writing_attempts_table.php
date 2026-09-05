<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Writing, the skill the schema declared and nothing implemented.
 *
 * `writing` has sat in the skills table since the first migration while
 * speaking got a table, a service layer, a queue worker and an API. A learner
 * could be assessed on five skills out of six.
 *
 * Two intake paths land in one table on purpose. Typed text arrives as itself;
 * a photograph of paper practice arrives as an image that a vision model reads
 * first. Beyond that difference the work is identical - the same rubric, the
 * same feedback, the same mastery evidence - and splitting them would mean
 * maintaining two graders that must agree.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('writing_attempts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');

            // What the learner was answering. Any of these may be set.
            $table->unsignedBigInteger('production_prompt_id')->nullable();
            $table->unsignedBigInteger('exercise_id')->nullable();
            $table->unsignedBigInteger('lesson_id')->nullable();
            $table->unsignedBigInteger('learning_session_id')->nullable();

            // typed  - the learner wrote it in the app
            // photo  - the learner wrote it on paper and photographed the page
            $table->string('source', 16)->default('typed');

            /*
             * The photograph, and what the vision model made of it.
             *
             * recognised_text is kept separate from `text` because they are not
             * the same claim: one is what the machine read, the other is what
             * the learner confirms they wrote. Scoring someone down for the
             * OCR's misreading would be worse than not scoring at all, so the
             * recognised text is shown back for correction and `text` only
             * becomes authoritative once they accept it.
             */
            $table->unsignedBigInteger('media_asset_id')->nullable();
            $table->longText('recognised_text')->nullable();
            $table->decimal('recognition_confidence', 4, 3)->nullable();
            $table->boolean('text_confirmed')->default(false);

            $table->longText('text')->nullable();
            $table->unsignedSmallInteger('word_count')->nullable();

            // pending -> recognising -> awaiting_confirmation -> scoring -> scored | failed
            $table->string('status', 24)->default('pending');
            $table->text('error')->nullable();

            /*
             * The rubric. Deliberately the same four dimensions the exam
             * examiner uses, so practice feedback and exam feedback speak the
             * same language to the learner rather than two private vocabularies.
             */
            $table->decimal('overall_score', 5, 2)->nullable();
            $table->decimal('task_achievement_score', 5, 2)->nullable();
            $table->decimal('coherence_score', 5, 2)->nullable();
            $table->decimal('grammar_score', 5, 2)->nullable();
            $table->decimal('vocabulary_score', 5, 2)->nullable();
            $table->decimal('mechanics_score', 5, 2)->nullable();

            $table->unsignedBigInteger('cefr_level_id')->nullable();

            // Per-span corrections and the prose feedback shown to the learner.
            $table->longText('corrections')->nullable();
            $table->longText('feedback')->nullable();

            $table->string('analyser', 48)->nullable();
            $table->timestamp('scored_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'id']);
            $table->index('production_prompt_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('writing_attempts');
    }
};
