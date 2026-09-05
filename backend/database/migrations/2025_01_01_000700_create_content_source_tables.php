<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_assets', function (Blueprint $table) {
            $table->id();
            $table->string('disk', 32)->default('s3');
            $table->string('path');
            $table->string('type', 24);                       // image|audio|video|document
            $table->string('mime', 128);
            $table->unsignedBigInteger('bytes')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('checksum', 64)->nullable()->comment('sha256, used to dedupe generated media');
            $table->string('origin', 32)->default('upload');  // upload|ingested|ai_generated
            $table->foreignId('ai_generation_id')->nullable();
            $table->string('copyright_status', 32)->default('owned');
            $table->string('attribution')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['disk', 'path']);
            $table->index('checksum');
            $table->index(['type', 'origin']);
        });

        Schema::create('source_documents', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('publisher')->nullable();
            $table->string('isbn', 32)->nullable();
            $table->foreignId('language_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('cefr_level_id')->nullable()->constrained('cefr_levels')->nullOnDelete();
            // Drives whether the pipeline may republish verbatim or must regenerate (spec 7).
            $table->string('copyright_status', 32)->default('unknown');
            $table->text('license_note')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 32)->default('uploaded'); // uploaded|processing|processed|failed|archived
            $table->timestamps();
            $table->softDeletes();

            $table->index('copyright_status');
            $table->index('status');
        });

        Schema::create('source_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_source_file_id')->nullable()->constrained('source_files')->cascadeOnDelete()
                ->comment('set when this file was extracted from an uploaded archive');
            $table->string('disk', 32)->default('s3');
            $table->string('path');
            $table->string('original_name');
            $table->string('relative_path')->nullable()->comment('path inside the archive, drives audio mapping');
            $table->string('kind', 24);                       // pdf|docx|txt|zip|audio|image|video
            $table->string('mime', 128);
            $table->unsignedBigInteger('bytes');
            $table->string('checksum', 64);
            $table->unsignedInteger('sequence')->nullable()->comment('numeric ordinal parsed from filename');
            $table->string('status', 32)->default('pending');
            $table->timestamps();

            $table->index(['source_document_id', 'kind']);
            $table->index('checksum');
            $table->index('parent_source_file_id');
        });

        Schema::create('source_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_file_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('page_number');
            $table->longText('text')->nullable();
            $table->unsignedInteger('char_count')->default(0);
            // Set when embedded text was too sparse and page-image understanding was used.
            $table->boolean('used_vision')->default(false);
            $table->foreignId('page_image_media_asset_id')->nullable()->constrained('media_assets')->nullOnDelete();
            $table->string('status', 32)->default('pending');
            $table->text('error')->nullable();
            $table->timestamps();

            $table->unique(['source_file_id', 'page_number']);
            $table->index('status');
        });

        // Semantic segments: the structured objects the pipeline derives from raw pages.
        Schema::create('source_segments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_page_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('source_segments')->cascadeOnDelete();
            // heading|unit|section|vocabulary|grammar|dialogue|exercise|answer_key|example|
            // instruction|table|caption|pronunciation_note
            $table->string('segment_type', 48);
            $table->unsignedInteger('position');
            $table->string('label')->nullable()->comment('e.g. "Unit 3", "Exercise 2b"');
            $table->longText('text')->nullable();
            $table->json('bbox')->nullable();
            $table->foreignId('cefr_level_id')->nullable()->constrained('cefr_levels')->nullOnDelete();
            $table->foreignId('topic_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('classification_confidence', 4, 3)->nullable();
            $table->timestamps();

            $table->index(['source_document_id', 'segment_type']);
            $table->index('parent_id');
        });

        Schema::create('ingestion_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 32)->default('queued'); // queued|running|completed|completed_with_errors|failed
            $table->unsignedTinyInteger('current_stage')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            // Rolled-up counters backing the import audit report (spec 49).
            $table->json('stats')->nullable();
            $table->timestamps();

            $table->index('status');
        });

        // One row per pipeline stage so a single bad page never fails the whole import.
        Schema::create('ingestion_stages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ingestion_job_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('stage_number');
            $table->string('stage_key', 64);
            $table->string('status', 32)->default('pending'); // pending|running|completed|skipped|failed
            $table->unsignedInteger('items_total')->default(0);
            $table->unsignedInteger('items_succeeded')->default(0);
            $table->unsignedInteger('items_failed')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->json('stats')->nullable();
            $table->timestamps();

            $table->unique(['ingestion_job_id', 'stage_number']);
        });

        Schema::create('ingestion_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ingestion_job_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ingestion_stage_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('severity', 16)->default('warning'); // info|warning|error
            $table->string('code', 64);
            $table->text('message');
            $table->nullableMorphs('subject');
            $table->json('context')->nullable();
            $table->boolean('resolved')->default(false);
            $table->timestamps();

            $table->index(['ingestion_job_id', 'severity']);
        });

        Schema::create('audio_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_file_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('media_asset_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('duration_ms');
            $table->string('codec', 32)->nullable();
            $table->unsignedInteger('sample_rate')->nullable();
            $table->unsignedTinyInteger('channels')->nullable();
            $table->longText('transcript')->nullable();
            $table->json('word_timestamps')->nullable();
            $table->string('transcription_status', 32)->default('pending');
            $table->string('detected_language', 12)->nullable();
            $table->unsignedTinyInteger('speaker_count')->nullable();
            $table->timestamps();

            $table->index('transcription_status');
        });

        // Candidate associations between an audio file and the content it belongs to.
        // Low-confidence rows surface in admin review rather than being applied blindly.
        Schema::create('audio_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('audio_asset_id')->constrained()->cascadeOnDelete();
            $table->morphs('mappable');                       // unit|lesson|exercise|dialogue|vocabulary_item
            $table->decimal('confidence', 4, 3);
            $table->string('method', 48);                     // filename|sequence|transcript_similarity|embedding|manual
            $table->json('evidence')->nullable();
            $table->string('review_status', 32)->default('pending'); // pending|approved|rejected|auto_approved
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['review_status', 'confidence']);
            $table->unique(['audio_asset_id', 'mappable_type', 'mappable_id'], 'audio_mappings_asset_mappable_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audio_mappings');
        Schema::dropIfExists('audio_assets');
        Schema::dropIfExists('ingestion_issues');
        Schema::dropIfExists('ingestion_stages');
        Schema::dropIfExists('ingestion_jobs');
        Schema::dropIfExists('source_segments');
        Schema::dropIfExists('source_pages');
        Schema::dropIfExists('source_files');
        Schema::dropIfExists('source_documents');
        Schema::dropIfExists('media_assets');
    }
};
