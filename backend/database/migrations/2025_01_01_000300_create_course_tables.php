<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('language_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_cefr_level_id')->constrained('cefr_levels');
            $table->foreignId('to_cefr_level_id')->constrained('cefr_levels');
            $table->string('slug')->unique();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('cover_path')->nullable();
            // general | exam | business | conversation ... drives which engine owns the syllabus
            $table->string('track', 32)->default('general');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['language_id', 'track']);
        });

        // Curriculum is versioned so published learner progress is never invalidated
        // by an editor reshaping the syllabus.
        Schema::create('course_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('status', 32)->default('draft'); // draft|review|published|archived
            $table->text('changelog')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['course_id', 'version']);
            $table->index(['course_id', 'status']);
        });

        Schema::create('modules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_version_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->foreignId('cefr_level_id')->nullable()->constrained('cefr_levels')->nullOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['course_version_id', 'position']);
        });

        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('module_id')->constrained()->cascadeOnDelete();
            $table->foreignId('topic_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->foreignId('cefr_level_id')->nullable()->constrained('cefr_levels')->nullOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->unsignedSmallInteger('estimated_minutes')->nullable();
            $table->timestamps();

            $table->unique(['module_id', 'position']);
            $table->index('topic_id');
        });

        Schema::create('lessons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('unit_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('summary')->nullable();
            $table->foreignId('cefr_level_id')->nullable()->constrained('cefr_levels')->nullOnDelete();
            // core | practice | review | checkpoint | remediation | generated
            $table->string('kind', 32)->default('core');
            $table->unsignedSmallInteger('position')->default(0);
            $table->unsignedSmallInteger('estimated_minutes')->default(10);
            $table->string('status', 32)->default('draft'); // draft|review|approved|published|rejected

            // Provenance (spec 7) - every teachable artefact tracks where it came from.
            $table->foreignId('source_document_id')->nullable();
            $table->unsignedInteger('source_page')->nullable();
            $table->string('source_section')->nullable();
            $table->string('generation_method', 32)->default('authored'); // authored|extracted|ai_generated|ai_enhanced
            $table->string('copyright_status', 32)->default('owned');

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['unit_id', 'position']);
            $table->index('status');
            $table->index('kind');
        });

        // A lesson is a sequence of interactive blocks (spec 15), never a textbook page.
        Schema::create('lesson_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lesson_id')->constrained()->cascadeOnDelete();
            $table->string('type', 48); // ai_intro|story|dialogue|listen_choose|repeat_after|...
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('title')->nullable();
            $table->text('instructions')->nullable();
            // Block-type-specific configuration only. Relational content lives in its own
            // tables and is referenced by id from here.
            $table->json('config')->nullable();
            $table->foreignId('exercise_id')->nullable();
            $table->foreignId('media_asset_id')->nullable();
            $table->foreignId('dialogue_id')->nullable();
            $table->unsignedSmallInteger('estimated_seconds')->default(60);
            $table->boolean('is_optional')->default(false);
            $table->timestamps();

            $table->unique(['lesson_id', 'position']);
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_blocks');
        Schema::dropIfExists('lessons');
        Schema::dropIfExists('units');
        Schema::dropIfExists('modules');
        Schema::dropIfExists('course_versions');
        Schema::dropIfExists('courses');
    }
};
