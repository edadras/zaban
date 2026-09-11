<?php

namespace App\Console\Commands;

use App\Models\MediaBrief;
use App\Services\Media\SheetComposer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * The cheap way to illustrate a course.
 *
 * `media:manifest` exports one brief per generation, which is correct and, at
 * the prices this account actually pays, roughly six times more than it needs
 * to be. A generation costs the same whether it returns one scene or nine, so
 * this asks for nine at once in a regular grid; `media:import` cuts the sheet
 * back into nine lesson images on its own seams.
 *
 * Output is one JSON object per sheet, ready to hand to the provider's image
 * call, with `cells` naming which brief each cell belongs to in reading order.
 * That list is the only thing tying a cell to a lesson, so it comes back into
 * `media:import` untouched.
 */
class ExportMediaSheet extends Command
{
    protected $signature = 'media:sheet
        {--cells=9 : how many briefs share one sheet (4, 6, 9, 12 or 16)}
        {--sheets=1 : how many sheets to export}
        {--kind=lesson_scene : which kind of brief to fill them with}
        {--resolution=4k : ask the provider for this; a sheet needs every pixel it can get}
        {--claim : mark the exported briefs generating so two runs do not collide}
        {--pretty : human-readable JSON}';

    protected $description = 'Export the next briefs grouped into contact sheets, nine lessons per generation';

    public function handle(SheetComposer $composer): int
    {
        $cells = (int) $this->option('cells');
        $wanted = (int) $this->option('sheets');
        $kind = (string) $this->option('kind');

        try {
            $composer->grid($cells);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $briefs = MediaBrief::renderable()
            ->where('kind', $kind)
            ->limit($cells * $wanted)
            ->get();

        if ($briefs->isEmpty()) {
            $this->output->write(json_encode(['sheets' => []])."\n");

            return self::SUCCESS;
        }

        $sheets = [];

        /*
         * A part-full sheet is left for the next run rather than rendered short:
         * the grid is demanded in the prompt by its exact shape, and asking for
         * nine cells while naming four scenes is how a sheet comes back as
         * something the slicer will refuse.
         */
        foreach ($briefs->chunk($cells) as $chunk) {
            if ($chunk->count() !== $cells) {
                $this->output->getErrorOutput()->writeln(
                    "{$chunk->count()} brief(s) left over; a sheet is only exported when it is full.",
                );

                continue;
            }

            $sheet = $composer->compose($chunk->values());
            $sheet['resolution'] = (string) $this->option('resolution');
            $sheet['model'] = $chunk->first()->model;
            $sheets[] = $sheet;

            if ($this->option('claim')) {
                MediaBrief::whereIn('id', $sheet['cells'])->update([
                    'status' => MediaBrief::STATUS_GENERATING,
                    'attempts' => DB::raw('attempts + 1'),
                ]);
            }
        }

        $this->output->write(json_encode(
            ['sheets' => $sheets],
            $this->option('pretty') ? JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES : JSON_UNESCAPED_SLASHES,
        )."\n");

        return self::SUCCESS;
    }
}
