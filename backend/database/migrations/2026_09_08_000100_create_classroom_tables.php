<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Live classes: a school, its coaches, and the sessions they teach.
 *
 * The rest of the product is one learner and an engine. This is the other half
 * of how the language is actually taught in Iran - a school, a coach, a class
 * at eight on Tuesday - and the two have to meet rather than sit side by side.
 * That meeting is what `practice_locks` is for: what happened in the class
 * decides what the learner practises afterwards.
 *
 * Everything here is addressed by the school. A coach belongs to a school, a
 * class belongs to a coach, and a learner reaches a class through an enrolment
 * the school made. Nothing is global, because a second school must not see the
 * first one's people.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schools', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->foreignId('owner_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('timezone', 64)->default('Asia/Tehran');
            $table->string('locale', 8)->default('fa');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        /*
         * One row per person per school, carrying the part they play there.
         * A coach at one school can be a learner at another, and the same
         * account is used for both: the role lives on the membership rather
         * than on the user, which is where `users.role` (the platform role)
         * would have forced a choice.
         */
        Schema::create('school_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 16)->index();          // owner | admin | coach | student
            $table->string('status', 16)->default('active'); // active | invited | suspended
            $table->string('display_name')->nullable();
            $table->text('bio')->nullable();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('joined_at')->nullable();
            $table->timestamps();

            $table->unique(['school_id', 'user_id', 'role']);
        });

        /*
         * The school deciding which coach looks after which learner. Kept apart
         * from class enrolment on purpose: a coach may be responsible for a
         * learner who is in none of their classes yet, and the admin asked for
         * exactly that - attach a student to a coach "if they want to".
         */
        Schema::create('coach_students', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('coach_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamp('assigned_at')->useCurrent();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->unique(['school_id', 'coach_id', 'student_id']);
            $table->index(['student_id', 'ended_at']);
        });

        Schema::create('class_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('coach_id')->constrained('users')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->foreignId('cefr_level_id')->nullable()->constrained('cefr_levels')->nullOnDelete();
            $table->foreignId('course_version_id')->nullable()->constrained('course_versions')->nullOnDelete();
            $table->unsignedSmallInteger('capacity')->default(20);
            $table->string('timezone', 64)->default('Asia/Tehran');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['school_id', 'is_active']);
        });

        Schema::create('class_group_students', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 16)->default('enrolled'); // enrolled | withdrawn
            $table->timestamp('enrolled_at')->useCurrent();
            $table->timestamp('withdrawn_at')->nullable();
            $table->timestamps();

            $table->unique(['class_group_id', 'user_id']);
        });

        /*
         * The recurring shape of a class - "Tuesdays and Thursdays, 18:00, ninety
         * minutes" - kept apart from the sessions it produces. Editing the rule
         * must not silently rewrite a session that has already been taught, so
         * the generator only ever creates sessions ahead of now.
         */
        Schema::create('class_schedule_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_group_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('weekday');          // 0 = Sunday .. 6 = Saturday
            $table->time('start_time');
            $table->unsignedSmallInteger('duration_minutes')->default(60);
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['class_group_id', 'is_active']);
        });

        Schema::create('class_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('coach_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('schedule_rule_id')->nullable()->constrained('class_schedule_rules')->nullOnDelete();
            $table->string('title')->nullable();
            $table->text('agenda')->nullable();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('status', 16)->default('scheduled'); // scheduled | live | ended | cancelled
            // The room as the media provider knows it. Unique and opaque, so a
            // cancelled session's name can never be reused by a live one.
            $table->string('room_name', 64)->unique();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at_actual')->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->foreignId('recording_media_asset_id')->nullable()->constrained('media_assets')->nullOnDelete();
            $table->timestamps();

            $table->index(['class_group_id', 'starts_at']);
            $table->index(['status', 'starts_at']);
        });

        /*
         * What the coach brings to a session. Either something they uploaded
         * (media_asset_id), something the corpus already teaches (lesson_id or
         * exercise_id), or text they typed - and the check below insists on
         * exactly one of those, because a material that points at nothing is a
         * blank slide the coach cannot see is blank until the class is running.
         */
        Schema::create('class_materials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by')->constrained('users')->cascadeOnDelete();
            $table->string('kind', 16);   // video | pdf | image | audio | text | quiz | lesson | exercise
            $table->string('title');
            $table->text('body')->nullable();
            $table->foreignId('media_asset_id')->nullable()->constrained('media_assets')->nullOnDelete();
            $table->foreignId('lesson_id')->nullable()->constrained('lessons')->cascadeOnDelete();
            $table->foreignId('exercise_id')->nullable()->constrained('exercises')->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamp('shared_at')->nullable();
            $table->timestamps();

            $table->index(['class_session_id', 'position']);
        });

        /*
         * Who is in the room, and what the coach has allowed them to do. The
         * permission columns are the system's own record rather than a mirror
         * of the media server's: the coach's decision has to survive a learner
         * reconnecting, and it has to be readable when the media server is not.
         */
        Schema::create('class_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 16)->default('student'); // coach | student | observer
            $table->boolean('can_publish_audio')->default(false);
            $table->boolean('can_publish_video')->default(false);
            $table->boolean('is_present')->default(false);
            $table->timestamp('hand_raised_at')->nullable();
            $table->timestamp('first_joined_at')->nullable();
            $table->timestamp('last_joined_at')->nullable();
            $table->timestamp('left_at')->nullable();
            $table->unsignedInteger('seconds_present')->default(0);
            $table->timestamps();

            $table->unique(['class_session_id', 'user_id']);
            $table->index(['class_session_id', 'is_present']);
        });

        /*
         * A question the coach puts to the room mid-class. It can be their own
         * wording, or an exercise the corpus already carries - which is how a
         * class question and the learner's later practice end up being the same
         * item rather than two unrelated things.
         */
        Schema::create('class_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asked_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('exercise_id')->nullable()->constrained('exercises')->nullOnDelete();
            $table->foreignId('class_material_id')->nullable()->constrained('class_materials')->nullOnDelete();
            $table->string('kind', 16)->default('open'); // open | poll | exercise
            $table->text('prompt');
            $table->json('options')->nullable();
            $table->json('correct_options')->nullable();
            // Null asks the whole room. A list narrows it to the learners the
            // coach picked, which is what "ask the students I choose" means.
            $table->json('addressed_user_ids')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['class_session_id', 'opened_at']);
        });

        Schema::create('class_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_question_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('body')->nullable();
            $table->json('selected_options')->nullable();
            $table->boolean('is_correct')->nullable();
            $table->timestamp('answered_at')->useCurrent();
            $table->timestamps();

            $table->unique(['class_question_id', 'user_id']);
        });

        /*
         * The class reaching into the rest of the day.
         *
         * While a lock is open the session composer stops choosing for itself
         * and draws only from the concepts the lock names, so the practice a
         * learner gets that evening is the material their coach taught that
         * afternoon. It expires on its own: a coach who forgets to lift it does
         * not freeze a learner's curriculum indefinitely.
         */
        Schema::create('practice_locks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('class_session_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->json('concept_ids');
            $table->json('lesson_ids')->nullable();
            $table->string('note')->nullable();
            $table->timestamp('starts_at')->useCurrent();
            $table->timestamp('expires_at');
            $table->timestamp('released_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('practice_locks');
        Schema::dropIfExists('class_answers');
        Schema::dropIfExists('class_questions');
        Schema::dropIfExists('class_participants');
        Schema::dropIfExists('class_materials');
        Schema::dropIfExists('class_sessions');
        Schema::dropIfExists('class_schedule_rules');
        Schema::dropIfExists('class_group_students');
        Schema::dropIfExists('class_groups');
        Schema::dropIfExists('coach_students');
        Schema::dropIfExists('school_members');
        Schema::dropIfExists('schools');
    }
};
