<?php

namespace App\Console\Commands;

use App\Models\MediaBrief;
use App\Services\Media\GeneratedMediaImporter;
use Illuminate\Console\Command;

/**
 * Reads {"results": {"<brief id>": "<url>", ...}} from a file or stdin.
 *
 * That shape is exactly what the provider's batch tools return once their jobs
 * are terminal - index in, index out - so a render round is: media:manifest,
 * generate, media:import.
 */
class ImportGeneratedMedia extends Command
{
    protected $signature = 'media:import
        {file? : JSON file of results; reads stdin when omitted}
        {--dry-run : report what would be imported without downloading}';

    protected $description = 'Pull finished generations into the project and attach them to the content';

    public function handle(GeneratedMediaImporter $importer): int
    {
        $raw = $this->argument('file')
            ? @file_get_contents($this->argument('file'))
            : stream_get_contents(STDIN);

        if ($raw === false || trim((string) $raw) === '') {
            $this->error('No input. Pass a JSON file or pipe one in.');

            return self::FAILURE;
        }

        $payload = json_decode($raw, true);

        if (! is_array($payload) || ! is_array($payload['results'] ?? null)) {
            $this->error('Expected JSON of the form {"results": {"<brief id>": "<url>"}}.');

            return self::FAILURE;
        }

        $results = $payload['results'];

        if ($this->option('dry-run')) {
            $this->table(
                ['brief', 'kind', 'status', 'url'],
                collect($results)->map(function ($entry, $id) {
                    $b = MediaBrief::find($id);
                    $where = is_array($entry) ? ($entry['file'] ?? $entry['url'] ?? '—') : $entry;

                    return [$id, $b?->kind ?? '—', $b?->status ?? 'MISSING', \Illuminate\Support\Str::limit((string) $where, 60)];
                })->values()->all(),
            );

            return self::SUCCESS;
        }

        /*
         * Files the local runner downloaded sit next to its results.json, and
         * are preferred over the URLs: provider links expire, so a manifest
         * brought back a day later would otherwise fail wholesale.
         */
        $baseDir = $this->argument('file')
            ? dirname(realpath($this->argument('file')) ?: $this->argument('file'))
            : null;

        $out = $importer->importMany($results, $baseDir);

        $this->info("imported {$out['imported']}, already present {$out['skipped']}, failed {$out['failed']}");

        foreach ($out['errors'] as $id => $message) {
            $this->warn("  brief {$id}: {$message}");
        }

        $remaining = MediaBrief::renderable()->count();
        $this->line("{$remaining} brief(s) still to render.");

        return $out['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
