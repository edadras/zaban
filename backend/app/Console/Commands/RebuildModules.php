<?php

namespace App\Console\Commands;

use App\Models\Module;
use App\Models\Unit;
use App\Services\Content\TableOfContentsParser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Replaces the mechanical "Units 1-10" modules with the books' own categories.
 *
 * Import order is not a curriculum. A learner looking for the language of
 * travel should find a module called Travel, not discover that it happens to
 * live in units 25 to 27.
 */
class RebuildModules extends Command
{
    protected $signature = 'content:modules
        {--dry-run : show the grouping that would be applied}';

    protected $description = 'Regroup units into the thematic modules the source books use';

    public function handle(TableOfContentsParser $parser): int
    {
        $versions = DB::table('course_versions')->pluck('id');
        $applied = 0;

        foreach ($versions as $versionId) {
            $units = Unit::whereHas('module', fn ($q) => $q->where('course_version_id', $versionId))
                ->orderBy('position')
                ->get(['id', 'title', 'position', 'module_id', 'cefr_level_id']);

            if ($units->isEmpty()) {
                continue;
            }

            $documentId = $this->documentFor($versionId);

            if (! $documentId) {
                $this->warn("Course version {$versionId}: no source document, left as it was.");

                continue;
            }

            $themes = $parser->parse(
                $this->contentsPages($documentId),
                (int) $units->max('position'),
            );

            if (! $parser->isCoherent($themes, (int) $units->max('position'))) {
                $this->warn("Course version {$versionId}: contents parse failed its sanity checks, left as it was.");

                continue;
            }

            $this->line("Course version {$versionId} - ".count($themes).' categories:');

            $assignment = $this->assign($units, $themes);

            foreach ($themes as $i => $theme) {
                $members = $assignment[$i] ?? collect();
                $this->line(sprintf(
                    '    %-42s units %s (%d)',
                    $theme['theme'],
                    $members->isEmpty() ? '-' : $members->min('position').'-'.$members->max('position'),
                    $members->count(),
                ));
            }

            if (! $this->option('dry-run')) {
                $this->apply($versionId, $themes, $assignment, $units);
                $applied++;
            }
        }

        $this->newLine();
        $this->info($this->option('dry-run')
            ? 'Dry run - nothing written.'
            : "Regrouped {$applied} course version(s).");

        return self::SUCCESS;
    }

    /**
     * Every unit goes to the last category that starts at or before it, so units
     * the contents page wrapped awkwardly still land in the right group rather
     * than being dropped.
     *
     * @return array<int,\Illuminate\Support\Collection>
     */
    private function assign($units, array $themes): array
    {
        $out = [];

        foreach ($units as $unit) {
            $index = 0;

            foreach ($themes as $i => $theme) {
                if ($theme['first_unit'] <= $unit->position) {
                    $index = $i;
                }
            }

            $out[$index] ??= collect();
            $out[$index]->push($unit);
        }

        return $out;
    }

    private function apply(int $versionId, array $themes, array $assignment, $units): void
    {
        DB::transaction(function () use ($versionId, $themes, $assignment, $units) {
            $old = Module::where('course_version_id', $versionId)->pluck('id');

            /*
             * (course_version_id, position) is unique and the outgoing modules
             * still hold 0..n. The new ones are therefore built past them and
             * renumbered once the old rows are gone - the alternative, deleting
             * first, would orphan every unit for the length of the transaction.
             */
            $offset = (int) Module::where('course_version_id', $versionId)->max('position') + 1;
            $new = [];

            foreach ($themes as $i => $theme) {
                $members = $assignment[$i] ?? collect();

                if ($members->isEmpty()) {
                    continue;
                }

                $module = Module::create([
                    'course_version_id' => $versionId,
                    'title' => $theme['theme'],
                    'position' => $offset + count($new),
                    // A module spans whatever its units span; taking the level of
                    // the first would understate a category that climbs.
                    'cefr_level_id' => $members->first()->cefr_level_id,
                ]);

                Unit::whereIn('id', $members->pluck('id'))->update(['module_id' => $module->id]);
                $new[] = $module->id;
            }

            // Only now that every unit has moved is it safe to drop the old ones.
            $stranded = Unit::whereIn('module_id', $old)->count();

            if ($stranded > 0) {
                throw new \RuntimeException("{$stranded} unit(s) still point at the old modules; aborting.");
            }

            Module::whereIn('id', $old)->delete();

            foreach ($new as $position => $id) {
                Module::where('id', $id)->update(['position' => $position]);
            }
        });
    }

    private function documentFor(int $versionId): ?int
    {
        return DB::table('lessons')
            ->join('units', 'units.id', '=', 'lessons.unit_id')
            ->join('modules', 'modules.id', '=', 'units.module_id')
            ->where('modules.course_version_id', $versionId)
            ->whereNotNull('lessons.source_document_id')
            ->value('lessons.source_document_id');
    }

    /** The first handful of pages, from the contents page onward. */
    private function contentsPages(int $documentId): array
    {
        $pages = DB::table('source_pages')
            ->join('source_files', 'source_files.id', '=', 'source_pages.source_file_id')
            ->where('source_files.source_document_id', $documentId)
            ->where('source_pages.page_number', '<=', 14)
            ->orderBy('source_pages.page_number')
            ->pluck('source_pages.text', 'source_pages.page_number');

        $start = null;

        foreach ($pages as $number => $text) {
            if (str_contains(substr((string) $text, 0, 400), 'Contents')) {
                $start = $number;

                break;
            }
        }

        return $start === null
            ? []
            : $pages->filter(fn ($t, $n) => $n >= $start && $n < $start + 4)->values()->all();
    }
}
