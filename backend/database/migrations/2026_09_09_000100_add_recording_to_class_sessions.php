<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recording a class.
 *
 * `recording_media_asset_id` was already here and nothing wrote it. These are
 * the columns that make it writable: what the media server called the job, how
 * far it has got, and - when it fails - why, because "the recording is missing"
 * is the kind of thing a school finds out about a week later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('class_sessions', function (Blueprint $table) {
            // The media server's own id for the recording job.
            $table->string('recording_egress_id', 64)->nullable()->after('recording_media_asset_id');

            // none | starting | recording | processing | ready | failed
            $table->string('recording_status', 16)->default('none')->after('recording_egress_id');

            $table->timestamp('recording_started_at')->nullable()->after('recording_status');
            $table->timestamp('recording_ended_at')->nullable()->after('recording_started_at');
            $table->unsignedInteger('recording_duration_ms')->nullable()->after('recording_ended_at');
            $table->string('recording_error')->nullable()->after('recording_duration_ms');

            $table->index('recording_egress_id');
            $table->index('recording_status');
        });
    }

    public function down(): void
    {
        Schema::table('class_sessions', function (Blueprint $table) {
            $table->dropIndex(['recording_egress_id']);
            $table->dropIndex(['recording_status']);
            $table->dropColumn([
                'recording_egress_id',
                'recording_status',
                'recording_started_at',
                'recording_ended_at',
                'recording_duration_ms',
                'recording_error',
            ]);
        });
    }
};
