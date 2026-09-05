<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Models\AudioMapping;
use App\Models\IngestionIssue;
use App\Models\IngestionJob;
use App\Models\SourceDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The content ingestion dashboard (spec 36).
 *
 * Shows what was imported, what the pipeline was unsure about, and what still
 * needs a human decision. The point is that nothing enters the curriculum
 * silently - low-confidence work surfaces here rather than being applied.
 */
class IngestionController extends ApiController
{
    /** Uploaded sources with their extraction status. */
    public function documents(Request $request)
    {
        $docs = SourceDocument::with(['files', 'language', 'cefrLevel'])
            ->withCount('files')
            ->orderByDesc('id')
            ->paginate(min(50, $request->integer('per_page', 20)));

        return $this->ok($docs->through(fn (SourceDocument $d) => [
            'id' => $d->id,
            'title' => $d->title,
            'status' => $d->status,
            'copyright_status' => $d->copyright_status,
            'cefr' => $d->cefrLevel?->code,
            'files' => $d->files_count,
            'pages' => DB::table('source_pages')
                ->whereIn('source_file_id', $d->files->pluck('id'))->count(),
            'segments' => DB::table('source_segments')->where('source_document_id', $d->id)->count(),
            'units' => DB::table('units')
                ->join('lessons', 'lessons.unit_id', '=', 'units.id')
                ->where('lessons.source_document_id', $d->id)->distinct()->count('units.id'),
            'lessons' => DB::table('lessons')->where('source_document_id', $d->id)->count(),
            'exercises' => DB::table('exercises')->where('source_document_id', $d->id)->count(),
            'created_at' => $d->created_at?->toIso8601String(),
        ]));
    }

    /** The per-stage audit for one import (spec 49). */
    public function job(Request $request, IngestionJob $job)
    {
        $job->load('stages', 'document');

        return $this->ok([
            'id' => $job->id,
            'document' => $job->document?->title,
            'status' => $job->status,
            'started_at' => $job->started_at?->toIso8601String(),
            'finished_at' => $job->finished_at?->toIso8601String(),
            'stats' => $job->stats,
            'stages' => $job->stages->sortBy('stage_number')->map(fn ($s) => [
                'number' => $s->stage_number,
                'key' => $s->stage_key,
                'status' => $s->status,
                'total' => $s->items_total,
                'succeeded' => $s->items_succeeded,
                'failed' => $s->items_failed,
            ])->values(),
            'issues' => [
                'error' => IngestionIssue::where('ingestion_job_id', $job->id)->where('severity', 'error')->count(),
                'warning' => IngestionIssue::where('ingestion_job_id', $job->id)->where('severity', 'warning')->count(),
            ],
        ]);
    }

    public function jobs(Request $request)
    {
        $jobs = IngestionJob::with('document')->orderByDesc('id')
            ->paginate(min(50, $request->integer('per_page', 20)));

        return $this->ok($jobs->through(fn (IngestionJob $j) => [
            'id' => $j->id,
            'document' => $j->document?->title,
            'status' => $j->status,
            'stats' => $j->stats,
            'finished_at' => $j->finished_at?->toIso8601String(),
        ]));
    }

    public function issues(Request $request)
    {
        $issues = IngestionIssue::query()
            ->when($request->filled('severity'), fn ($q) => $q->where('severity', $request->string('severity')))
            ->when($request->filled('job_id'), fn ($q) => $q->where('ingestion_job_id', $request->integer('job_id')))
            ->where('resolved', $request->boolean('resolved', false))
            ->orderByDesc('id')
            ->paginate(min(100, $request->integer('per_page', 50)));

        return $this->ok($issues);
    }

    /**
     * Audio the pipeline could not confidently place. High-confidence mappings
     * are applied automatically; anything below the bar waits here.
     */
    public function unmappedAudio(Request $request)
    {
        $threshold = (float) $request->input('threshold', 0.85);

        $rows = AudioMapping::with('audioAsset.mediaAsset')
            ->where(fn ($q) => $q->where('review_status', 'pending')->orWhere('confidence', '<', $threshold))
            ->orderBy('confidence')
            ->paginate(min(100, $request->integer('per_page', 50)));

        return $this->ok($rows->through(fn (AudioMapping $m) => [
            'id' => $m->id,
            'file' => $m->audioAsset?->mediaAsset?->path,
            'target_type' => class_basename($m->mappable_type),
            'target_id' => $m->mappable_id,
            'confidence' => (float) $m->confidence,
            'method' => $m->method,
            'evidence' => $m->evidence,
            'review_status' => $m->review_status,
        ]), ['threshold' => $threshold]);
    }

    public function reviewAudioMapping(Request $request, AudioMapping $mapping)
    {
        $data = $request->validate([
            'decision' => ['required', 'in:approved,rejected'],
        ]);

        $mapping->update([
            'review_status' => $data['decision'],
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        return $this->ok(['id' => $mapping->id, 'review_status' => $mapping->review_status]);
    }

    /** Headline import audit numbers across every source. */
    public function summary(Request $request)
    {
        return $this->ok([
            'documents' => SourceDocument::count(),
            'pages' => DB::table('source_pages')->count(),
            'source_chars' => (int) DB::table('source_pages')->sum('char_count'),
            'segments_by_type' => DB::table('source_segments')
                ->select('segment_type', DB::raw('COUNT(*) n'))
                ->groupBy('segment_type')->pluck('n', 'segment_type'),
            'units' => DB::table('units')->count(),
            'lessons' => DB::table('lessons')->count(),
            'vocabulary_items' => DB::table('vocabulary_items')->count(),
            'concepts' => DB::table('concepts')->count(),
            'exercises' => DB::table('exercises')->count(),
            'audio_assets' => DB::table('audio_assets')->count(),
            'images' => DB::table('media_assets')->where('type', 'image')->count(),
            'pending_audio_review' => AudioMapping::where('review_status', 'pending')->count(),
            'unresolved_issues' => IngestionIssue::where('resolved', false)->count(),
        ]);
    }
}
