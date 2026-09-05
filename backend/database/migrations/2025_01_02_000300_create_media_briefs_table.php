<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The media render manifest.
 *
 * Generation happens against a purchased, time-boxed unlimited window, which
 * makes "work out what to render while rendering" the wrong shape entirely: the
 * expensive resource is the clock, not the compute. So every image the course
 * needs is decided, prompted, prioritised and reviewable here BEFORE the window
 * opens, and the window is then spent only on rendering.
 *
 * The table is also the audit trail the content rules require: every generated
 * asset can be traced back to the exact prompt and model that produced it, and
 * a bad batch can be re-queued by flipping status rather than by re-deriving
 * anything.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_briefs', function (Blueprint $table) {
            $table->id();

            // What this image is for. Drives prompt shape and priority.
            $table->string('kind', 32);

            // What it attaches to - a lesson, a vocabulary sense, a character.
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');

            $table->string('model', 64);
            $table->text('prompt');
            // Only populated for models that genuinely accept one; see
            // PromptBuilder::forModel(). Null here means the exclusions were
            // folded into the prompt, not that there were none.
            $table->text('negative')->nullable();
            $table->string('aspect_ratio', 16);
            $table->string('resolution', 8)->default('2k');

            /*
             * Rendering order. A time-boxed window can run out, so the manifest
             * is explicitly ordered by teaching value: if only half of it gets
             * made, it must be the more useful half. Lower number renders first.
             */
            $table->unsignedSmallInteger('priority')->default(500);

            /*
             * pending    - decided, not yet sent
             * generating - handed to the provider, job id recorded
             * generated  - provider returned a URL, not yet pulled into the project
             * imported   - stored locally and linked to its subject; terminal success
             * failed     - provider or import error, retryable
             * skipped    - deliberately not generated, with a reason
             */
            $table->string('status', 16)->default('pending');
            $table->string('skip_reason', 255)->nullable();

            /*
             * Identity of the request, not of the row: model + prompt + shape.
             * Lets a rebuild recognise briefs it has already made and leave
             * their generated results alone instead of re-rendering them.
             */
            $table->string('request_hash', 64);

            $table->string('external_job_id', 64)->nullable();
            $table->text('result_url')->nullable();
            $table->unsignedBigInteger('media_asset_id')->nullable();
            $table->text('error')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('generated_at')->nullable();

            $table->timestamps();

            // One brief per subject per kind: the manifest is a plan, not a log.
            $table->unique(['kind', 'subject_type', 'subject_id'], 'media_briefs_subject_unique');
            $table->index(['status', 'priority'], 'media_briefs_render_order');
            $table->index('request_hash');
            $table->index('external_job_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_briefs');
    }
};
