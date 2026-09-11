<?php

namespace App\Console\Commands;

use App\Models\MediaBrief;
use App\Services\Media\GeneratedMediaImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Reads {"results": {"<brief id>": "<url>", ...}} from a file or stdin.
 *
 * That shape is exactly what the provider's batch tools return once their jobs
 * are terminal - index in, index out - so a render round is: media:manifest,
 * generate, media:import.
 *
 * A payload may also carry "sheets": one contact sheet holding nine lessons'
 * artwork, exported by media:sheet and cut back apart here. Both keys may
 * appear together.
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

        if (! is_array($payload) || (! is_array($payload['results'] ?? null) && ! is_array($payload['sheets'] ?? null))) {
            $this->error('Expected JSON with "results" (one image per brief) or "sheets" (a grid of them).');

            return self::FAILURE;
        }

        $results = $payload['results'] ?? [];
        $sheets = $payload['sheets'] ?? [];

        if ($this->option('dry-run')) {
            $this->table(
                ['brief', 'kind', 'status', 'url'],
                collect($results)->map(function ($entry, $id) {
                    $b = MediaBrief::find($id);
                    $where = is_array($entry) ? ($entry['file'] ?? $entry['url'] ?? '—') : $entry;

                    return [$id, $b?->kind ?? '—', $b?->status ?? 'MISSING', Str::limit((string) $where, 60)];
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

        foreach ($sheets as $sheet) {
            $one = $importer->importSheet($sheet, $baseDir);

            foreach (['imported', 'skipped', 'failed'] as $k) {
                $out[$k] += $one[$k];
            }
            $out['errors'] += $one['errors'];
        }

        $this->info("imported {$out['imported']}, already present {$out['skipped']}, failed {$out['failed']}");

        foreach ($out['errors'] as $id => $message) {
            $this->warn("  brief {$id}: {$message}");
        }

        $remaining = MediaBrief::renderable()->count();
        $this->line("{$remaining} brief(s) still to render.");

        return $out['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
