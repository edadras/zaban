<?php

namespace App\Services\Media;

use App\Models\MediaBrief;
use Illuminate\Support\Collection;

/**
 * Nine lessons' artwork in one generation.
 *
 * A picture costs the same whether it holds one scene or nine, so asking for a
 * regular grid and cutting it up is nine lesson images for the price of one.
 * Measured on this account: 0.75 credits for a 4K sheet against 0.5 for a
 * single 2K render, which is 0.083 per lesson instead of 0.5 - the difference
 * between illustrating a hundred lessons and illustrating all of them.
 *
 * The cost is resolution. A 4:3 sheet at 4K comes back 3312x2480, so a 3x3 grid
 * gives cells of about 1080x810. The client draws this artwork in a 4:3 box no
 * wider than 720 logical points, which on a high-density phone is roughly
 * 1200x900 physical - so a cell is within about ten percent of what is actually
 * displayed, and nobody sees the difference. Go to 4x4 and cells drop to about
 * 810x610, which is visibly soft on a good screen. Hence three by three.
 *
 * Two things make the cutting safe rather than hopeful. The grid is demanded in
 * the prompt in the strongest terms available - equal cells, strict alignment,
 * even gutters, nothing crossing a gutter - and the slicer then finds the
 * gutters in the returned pixels rather than assuming exact thirds, so a sheet
 * that came back slightly irregular is still cut on its own seams. A sheet that
 * did not come back as a grid at all is rejected by the slicer, not quietly
 * carved into nine wrong pictures.
 */
class SheetComposer
{
    /**
     * Cells per sheet, and the grid they are laid out in.
     *
     * Keyed by cell count so a caller can ask for a coarser sheet where the
     * artwork matters less - vocabulary cards are drawn much smaller than a
     * lesson scene, so they can take a denser grid.
     *
     * @var array<int,array{cols:int,rows:int}>
     */
    public const GRIDS = [
        4 => ['cols' => 2, 'rows' => 2],
        6 => ['cols' => 3, 'rows' => 2],
        9 => ['cols' => 3, 'rows' => 3],
        12 => ['cols' => 4, 'rows' => 3],
        16 => ['cols' => 4, 'rows' => 4],
    ];

    /**
     * The look every cell on the sheet shares, stated once instead of sixteen
     * times.
     *
     * A single-image brief carries its own house style, and a sheet that
     * repeated it per cell would spend most of the prompt restating the
     * lighting. But the style is not one style: a lesson scene wants
     * documentary photography of a situation, a vocabulary card wants one
     * object lit cleanly on plain paper. Asking for the wrong one is not
     * cosmetic - sixteen moody, shallow-focus vocabulary cards are sixteen
     * pictures where the object is the part that is out of focus.
     *
     * @var array<string,string>
     */
    private const STYLES = [
        MediaBrief::KIND_LESSON_SCENE => 'natural available light, warm and neutral palette, '
            .'documentary photography, shallow depth of field',
        MediaBrief::KIND_VOCABULARY_CARD => 'one unambiguous subject centred on a plain, '
            .'uncluttered background, clean even product-photography lighting, everything in focus',
        MediaBrief::KIND_CHARACTER_PORTRAIT => 'natural available light, warm and neutral palette, '
            .'head and shoulders, plain background',
    ];

    /** Never rendered into a sheet, on top of whatever the briefs exclude. */
    private const SHEET_NEGATIVE = 'irregular layout, uneven cells, cells of different sizes, '
        .'overlapping panels, a scene crossing a gutter, torn edges, scrapbook, polaroid frames, '
        .'drop shadows, page curl, thick borders, coloured gutters';

    public function grid(int $cells): array
    {
        return self::GRIDS[$cells] ?? throw new \InvalidArgumentException(
            "No grid defined for {$cells} cells; use one of ".implode(', ', array_keys(self::GRIDS)).'.',
        );
    }

    /**
     * The aspect ratio the whole sheet must be asked for.
     *
     * A grid of cells at ratio r laid out c across and w down is itself at
     * ratio r * c / w. Getting this wrong is not cosmetic: ask for a square
     * sheet of 4:3 cells and the model either distorts every scene or leaves
     * bands of empty paper, and the slicer cuts cells of the wrong shape.
     */
    public function sheetRatio(string $cellRatio, int $cells): string
    {
        [$w, $h] = array_map('intval', explode(':', $cellRatio));
        $grid = $this->grid($cells);

        $sheetW = $w * $grid['cols'];
        $sheetH = $h * $grid['rows'];
        $g = $this->gcd($sheetW, $sheetH);

        return ($sheetW / $g).':'.($sheetH / $g);
    }

    private function gcd(int $a, int $b): int
    {
        return $b === 0 ? $a : $this->gcd($b, $a % $b);
    }

    /**
     * Compose one sheet request from the briefs that will fill it.
     *
     * @param  Collection<int,MediaBrief>  $briefs  in the order their cells are read
     * @return array{prompt:string, aspect_ratio:string, cols:int, rows:int, cells:array<int,int>}
     */
    public function compose(Collection $briefs): array
    {
        $cells = $briefs->count();
        $grid = $this->grid($cells);
        $cellRatio = $briefs->first()->aspect_ratio;

        if ($briefs->pluck('aspect_ratio')->unique()->count() > 1) {
            throw new \InvalidArgumentException('A sheet cannot mix cell shapes.');
        }

        // One sheet, one house style - and the style is chosen by kind, so a
        // sheet of two kinds has no one style to ask for.
        if ($briefs->pluck('kind')->unique()->count() > 1) {
            throw new \InvalidArgumentException('A sheet cannot mix kinds of brief.');
        }

        $scenes = $briefs->values()
            ->map(fn (MediaBrief $b, int $i) => ($i + 1).'. '.$this->oneLine($b))
            ->implode("\n");

        $prompt = implode("\n\n", [
            sprintf(
                'A contact sheet laid out as a PERFECTLY REGULAR %d x %d GRID of exactly %d equal '
                .'rectangular photographs, %d across and %d down. Every cell is exactly the same size '
                .'and shape, aligned to a strict grid, separated by thin even white gutters of '
                .'identical width. Each cell is a separate, complete, photorealistic scene filling '
                .'its own cell edge to edge. No cell is merged with another, none is larger than '
                .'another, and no scene crosses a gutter.',
                $grid['cols'],
                $grid['rows'],
                $cells,
                $grid['cols'],
                $grid['rows'],
            ),
            sprintf('Reading left to right, top to bottom, the %d cells are:', $cells)."\n".$scenes,
            'Every cell: '.$this->style($briefs).'. '
            .'Culturally neutral: no religious symbols, no national flags, '
            .'no region-specific signage. Age-appropriate for a general adult audience. '
            .'No writing of any kind anywhere in the image.',
            'Do not include any of the following: '.$this->exclusions($briefs).'.',
        ]);

        return [
            'prompt' => $prompt,
            'aspect_ratio' => $this->sheetRatio($cellRatio, $cells),
            'cols' => $grid['cols'],
            'rows' => $grid['rows'],
            'cells' => $briefs->pluck('id')->all(),
        ];
    }

    /**
     * The shared look for this sheet, taken from what the briefs are for.
     *
     * An unknown kind falls back to the documentary style rather than refusing:
     * a sheet in a style that is merely not ideal is better than no sheet, and
     * the mixed-kind guard above already stops the ambiguous case.
     */
    private function style(Collection $briefs): string
    {
        return self::STYLES[$briefs->first()->kind] ?? self::STYLES[MediaBrief::KIND_LESSON_SCENE];
    }

    /**
     * One cell's scene, on one line.
     *
     * A numbered list is the only thing holding cell to lesson, so a scene that
     * runs over several lines lets the model lose which number it is on.
     */
    private function oneLine(MediaBrief $brief): string
    {
        $scene = trim((string) ($brief->scene ?: $brief->prompt));

        return trim(preg_replace('/\s+/u', ' ', $scene));
    }

    /**
     * The briefs' own exclusions, plus the ones that are about being a sheet.
     *
     * Taken from the briefs rather than restated here: they carry the house
     * rules - no text, no watermark, no brand names - and a sheet that quietly
     * dropped them would be nine images breaking them at once.
     *
     * @param  Collection<int,MediaBrief>  $briefs
     */
    private function exclusions(Collection $briefs): string
    {
        /*
         * Minus the ones that forbid being a montage. Every single-image brief
         * carries them - it is how the scene prompt stops the model returning a
         * contact sheet - and a sheet that inherited them would be asking for a
         * grid of nine while forbidding grids, which a model resolves by
         * ignoring whichever half it likes.
         */
        $montage = collect(explode(', ', PromptBuilder::MONTAGE_EXCLUSIONS))
            ->map(fn (string $s) => trim($s))
            ->all();

        $fromBriefs = $briefs
            ->flatMap(fn (MediaBrief $b) => explode(',', (string) $this->negativeOf($b)))
            ->map(fn (string $s) => trim($s))
            ->filter()
            ->reject(fn (string $s) => in_array($s, $montage, true))
            ->unique()
            ->values();

        return $fromBriefs->merge(explode(', ', self::SHEET_NEGATIVE))->unique()->implode(', ');
    }

    /**
     * A brief's exclusions, wherever they ended up.
     *
     * PromptBuilder::forModel folds them into the prompt for models with no
     * negative parameter - which is every image model in the catalogue - so for
     * most briefs `negative` is null and the list has to be read back out of
     * the prompt text.
     */
    private function negativeOf(MediaBrief $brief): string
    {
        if ($brief->negative) {
            return $brief->negative;
        }

        if (preg_match('/Do not include any of the following:\s*(.+?)\.\s*$/us', (string) $brief->prompt, $m)) {
            return $m[1];
        }

        return '';
    }
}
