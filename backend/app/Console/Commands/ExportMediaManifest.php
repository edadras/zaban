<?php

namespace App\Console\Commands;

use App\Models\MediaBrief;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Hands the next slice of the manifest to whatever is doing the rendering.
 *
 * Generation runs outside the application: the Higgsfield CLI needs an
 * interactive browser login this environment cannot complete, so the practical
 * route is an operator (or an agent session) driving the provider's batch API
 * with this export as input, then feeding the results back through media:import.
 * Keeping that seam explicit is what lets the backend stay the source of truth
 * for what exists and what still needs making.
 */
class ExportMediaManifest extends Command
{
    protected $signature = 'media:manifest
        {--limit=12 : how many briefs to export (12 matches the provider batch size)}
        {--kind= : restrict to one kind}
        {--claim : mark the exported briefs as generating so parallel runs do not collide}
        {--pretty : human-readable JSON}';

    protected $description = 'Export the next pending briefs as a provider-ready batch';

    /**
     * A publicly reachable URL for the still this brief animates.
     *
     * Providers fetch the seed image themselves, so a local path is no use to
     * them. Where the media disk cannot produce a URL the brief is exported
     * without one and degrades to text-to-video, which is reported rather than
     * silently accepted - a clip generated without its seed will not match the
     * lesson it belongs to.
     */
    private function sourceStillUrl(MediaBrief $brief): ?string
    {
        $asset = $brief->sourceBrief?->mediaAsset;

        if (! $asset) {
            return null;
        }

        try {
            return Storage::disk($asset->disk)->url($asset->path);
        } catch (\Throwable) {
            return null;
        }
    }

    public function handle(): int
    {
        $query = MediaBrief::renderable();

        if ($kind = $this->option('kind')) {
            $query->where('kind', $kind);
        }

        $briefs = $query->limit((int) $this->option('limit'))->get();

        if ($briefs->isEmpty()) {
            $this->output->write(json_encode(['requests' => []])."\n");

            return self::SUCCESS;
        }

        $briefs->load('sourceBrief.mediaAsset');

        $requests = $briefs->map(fn (MediaBrief $b) => [
            'index' => $b->id,
            'kind' => $b->kind,
            'params' => array_filter([
                'model' => $b->model,
                'prompt' => $b->prompt,
                'negative_prompt' => $b->negative,
                'aspect_ratio' => $b->aspect_ratio,
                'resolution' => $b->resolution,
                'duration' => $b->duration_seconds ? (string) $b->duration_seconds : null,
                // A clip that animates a still is seeded from it, which is what
                // keeps the cast and the room the same as the lesson's own
                // artwork instead of a fresh invention.
                'image_url' => $this->sourceStillUrl($b),
            ], fn ($v) => $v !== null && $v !== ''),
        ])->values()->all();

        if ($this->option('claim')) {
            MediaBrief::whereIn('id', $briefs->pluck('id'))->update([
                'status' => MediaBrief::STATUS_GENERATING,
                'attempts' => DB::raw('attempts + 1'),
            ]);
        }

        $this->output->write(json_encode(
            ['requests' => $requests],
            $this->option('pretty') ? JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES : JSON_UNESCAPED_SLASHES,
        )."\n");

        return self::SUCCESS;
    }
}
