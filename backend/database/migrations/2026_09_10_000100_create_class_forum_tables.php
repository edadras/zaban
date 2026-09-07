<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The class's own board.
 *
 * Scoped to a class group rather than to the school or the platform, and that
 * is the whole design: a beginner asking why "he don't" is wrong should be
 * asking eighteen classmates and their coach, not the internet. It also makes
 * the authorisation a single question - are you on this roll - which is the
 * same question the rest of the module already answers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('class_threads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_id')->constrained('users')->cascadeOnDelete();

            // question: somebody is stuck. discussion: anything else.
            // announcement: the coach talking to the class.
            $table->string('kind', 16)->default('question');
            $table->string('title', 200);
            $table->longText('body')->nullable();

            // open | resolved | closed
            $table->string('status', 16)->default('open');

            // A coach's answer, or the one the class voted useful.
            $table->foreignId('accepted_reply_id')->nullable();

            $table->timestamp('pinned_at')->nullable();
            $table->timestamp('locked_at')->nullable();

            // Moderation is a state, not a delete: a post taken down is still
            // there for the coach and the school to look at.
            $table->timestamp('hidden_at')->nullable();
            $table->foreignId('hidden_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('hidden_reason')->nullable();

            $table->unsignedInteger('reply_count')->default(0);
            $table->unsignedInteger('view_count')->default(0);
            $table->timestamp('last_activity_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['class_group_id', 'status']);
            $table->index(['class_group_id', 'last_activity_at']);
        });

        Schema::create('class_thread_replies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_thread_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_id')->constrained('users')->cascadeOnDelete();

            // One level of nesting only. A classroom board that grows a tree
            // becomes unreadable on a phone, which is where the learners are.
            $table->foreignId('parent_reply_id')->nullable()
                ->constrained('class_thread_replies')->cascadeOnDelete();

            $table->longText('body');

            // Marked apart in the interface: an answer from the person who
            // teaches the class is not just another opinion.
            $table->boolean('is_coach_answer')->default(false);

            /*
             * An answer the assistant offered before a person got to it.
             * Labelled in the payload rather than hidden, because a learner
             * has to be able to tell who is talking to them - and because the
             * coach corrects it, which only works if it is visibly a draft.
             */
            $table->boolean('is_ai_answer')->default(false);
            $table->boolean('ai_endorsed')->default(false);

            $table->unsignedInteger('helpful_count')->default(0);

            $table->timestamp('hidden_at')->nullable();
            $table->foreignId('hidden_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('hidden_reason')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['class_thread_id', 'created_at']);
        });

        /*
         * Pictures, video and audio on a post.
         *
         * Polymorphic because a photograph of a page of homework is the same
         * thing whether it hangs off a question, an answer, or later a piece of
         * submitted work - and because the alternative is three tables that
         * drift apart.
         */
        Schema::create('class_attachments', function (Blueprint $table) {
            $table->id();
            $table->morphs('attachable');
            $table->foreignId('media_asset_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by')->constrained('users')->cascadeOnDelete();
            $table->string('kind', 16)->default('image');
            $table->string('caption')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        /*
         * "This answered it."
         *
         * A single count on the reply would let one learner press it eight
         * times; a row per person is what makes the number mean something.
         */
        Schema::create('class_thread_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_thread_reply_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['class_thread_reply_id', 'user_id']);
        });

        /*
         * What each person has already read.
         *
         * One row per person per thread rather than per reply: the question a
         * board has to answer is "is there anything new here", and that only
         * needs the moment they last looked.
         */
        Schema::create('class_thread_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_thread_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('read_at');

            $table->unique(['class_thread_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('class_thread_reads');
        Schema::dropIfExists('class_thread_votes');
        Schema::dropIfExists('class_attachments');
        Schema::dropIfExists('class_thread_replies');
        Schema::dropIfExists('class_threads');
    }
};
