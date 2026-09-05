<?php

namespace App\Console\Commands;

use App\Models\MediaBrief;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

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

        $requests = $briefs->map(fn (MediaBrief $b) => [
            'index' => $b->id,
            'params' => array_filter([
                'model' => $b->model,
                'prompt' => $b->prompt,
                'negative_prompt' => $b->negative,
                'aspect_ratio' => $b->aspect_ratio,
                'resolution' => $b->resolution,
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
