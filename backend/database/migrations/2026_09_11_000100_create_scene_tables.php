<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Acted scenes: conversation practice that is watched and joined rather than
 * read.
 *
 * A scene is the same situation the roleplay scenarios already describe - a
 * clinic, a check-in desk, a table in a restaurant - but written out line by
 * line and staged: who speaks, where the camera is, what the face and hands
 * are doing, and which line the learner has to produce themselves. The client
 * renders it in real time from the rig and the room; nothing here is a video
 * file, so a line can be replayed, slowed down or handed to the learner
 * without re-rendering anything.
 *
 * The words and the staging are kept apart from the voice on purpose. A beat
 * names a recording and a window inside it, so a line can be spoken by the
 * course's own recorded voices; when no recording covers it, the same beat
 * plays through the project's voice chain instead. Changing the text drops the
 * audio rather than letting a line say one thing and sound like another.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scenes', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 120)->unique();

            // The situation this scene acts out. Scenes live inside conversation
            // practice, so a scene usually belongs to a scenario - but a scene
            // written for a unit of the course does not have to.
            $table->foreignId('conversation_scenario_id')->nullable()
                ->constrained('conversation_scenarios')->nullOnDelete();
            $table->foreignId('lesson_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('language_id')->constrained();
            $table->foreignId('cefr_level_id')->nullable()->constrained('cefr_levels')->nullOnDelete();

            $table->string('title', 200);
            $table->string('title_fa', 200)->nullable();
            $table->text('situation')->nullable();
            $table->text('situation_fa')->nullable();

            // Which room the kit should build, and how bright it is.
            $table->string('environment', 32)->default('clinic');
            $table->decimal('light', 3, 2)->default(1.00);

            /*
             * The two people on stage and where they stand. Two, not many: the
             * camera cuts, the roleplay and the "you are this one" choice all
             * assume a pair, and pretending otherwise would give a scene the
             * client cannot actually shoot.
             */
            $table->json('cast');

            // Furniture, and the words the scene is teaching.
            $table->json('props')->nullable();
            $table->json('vocabulary')->nullable();

            // What a learner should be able to do afterwards, in their words.
            $table->json('objectives')->nullable();

            $table->unsignedSmallInteger('estimated_seconds')->default(240);
            $table->string('status', 16)->default('draft');   // draft | published
            $table->timestamp('published_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'cefr_level_id']);
        });

        Schema::create('scene_beats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scene_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');

            // Which of the scene's two roles says this. The role key, not a
            // character id: recasting a scene must not rewrite every line.
            $table->string('role', 24);

            $table->text('text');
            $table->text('translation_fa')->nullable();

            // Staging. Vocabulary the client highlights inside this line.
            $table->string('camera', 24)->default('two_person');
            $table->string('animation', 24)->default('talking');
            $table->string('gesture', 24)->default('none');
            $table->string('expression', 24)->default('neutral');

            /*
             * The voice.
             *
             * A window into a recording rather than a file of its own, because
             * the course's audio is one track per exercise: the line is at
             * 12.4s of the unit recording, not in a file called line-4.mp3.
             * Null start/end mean the whole asset.
             */
            $table->foreignId('audio_media_asset_id')->nullable()
                ->constrained('media_assets')->nullOnDelete();
            $table->unsignedInteger('audio_start_ms')->nullable();
            $table->unsignedInteger('audio_end_ms')->nullable();

            // How the window was arrived at, and whether a person has agreed
            // with it. Machine-cut audio is playable but never silently
            // presented as checked.
            $table->string('audio_method', 32)->nullable();
            $table->decimal('audio_confidence', 4, 3)->nullable();
            $table->string('audio_review_status', 16)->default('pending');

            /*
             * What the learner does at this beat.
             *
             * watch    - they watch it happen
             * speak    - they say this line (microphone, or typed when they cannot)
             * choose   - they pick what the person should say next
             * recall   - they supply a missing word from the line
             */
            $table->string('interaction', 16)->default('watch');
            $table->text('prompt')->nullable();
            $table->text('prompt_fa')->nullable();

            // choose: the options. speak/recall: the answers that count.
            $table->json('choices')->nullable();
            $table->json('accept')->nullable();
            $table->text('hint')->nullable();
            $table->text('hint_fa')->nullable();

            $table->timestamps();

            $table->unique(['scene_id', 'position']);
            $table->index('interaction');
        });

        Schema::create('scene_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('scene_id')->constrained()->cascadeOnDelete();

            // Which of the two roles the learner took. Watching the whole thing
            // through is a role too, and the commonest first time.
            $table->string('role', 24)->nullable();
            $table->string('mode', 16)->default('guided');   // watch | guided | roleplay

            $table->string('status', 16)->default('active'); // active | completed | abandoned
            $table->unsignedSmallInteger('position')->default(0);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('cleared')->default(0);
            $table->decimal('score', 5, 2)->nullable();
            $table->json('summary')->nullable();

            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });

        Schema::create('scene_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scene_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('scene_beat_id')->constrained()->cascadeOnDelete();

            $table->string('kind', 16);                       // speak | choose | recall
            $table->text('given')->nullable();
            $table->foreignId('speech_attempt_id')->nullable()
                ->constrained('speech_attempts')->nullOnDelete();

            $table->unsignedTinyInteger('similarity')->nullable();
            $table->boolean('accepted')->default(false);
            $table->unsignedTinyInteger('try_number')->default(1);
            $table->json('detail')->nullable();

            $table->timestamps();

            $table->index(['scene_session_id', 'scene_beat_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scene_attempts');
        Schema::dropIfExists('scene_sessions');
        Schema::dropIfExists('scene_beats');
        Schema::dropIfExists('scenes');
    }
};
