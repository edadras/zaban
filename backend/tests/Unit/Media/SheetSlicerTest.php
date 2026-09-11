<?php

namespace Tests\Unit\Media;

use App\Services\Media\SheetSlicer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Cutting nine lessons out of one picture.
 *
 * The dangerous failure here is not an exception, it is a quiet one: a sheet
 * cut in the wrong places still yields nine images, they still import, they
 * still attach, and nine lessons end up showing slivers of each other's
 * artwork. Nobody finds out until a learner does. So these tests care as much
 * about what the slicer refuses as about what it cuts.
 */
class SheetSlicerTest extends TestCase
{
    private SheetSlicer $slicer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->slicer = new SheetSlicer;
    }

    /**
     * A sheet built the way the model is asked to build one: equal cells of
     * flat colour, separated by white gutters.
     *
     * @param  array<int,int>  $colWidths  so a test can make the cells uneven
     */
    private function sheet(
        int $cols,
        int $rows,
        int $cellW = 300,
        int $cellH = 200,
        int $gutter = 12,
        array $colWidths = [],
    ): string {
        $widths = $colWidths ?: array_fill(0, $cols, $cellW);
        $w = array_sum($widths) + $gutter * ($cols - 1);
        $h = $cellH * $rows + $gutter * ($rows - 1);

        $im = imagecreatetruecolor($w, $h);
        imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255));

        $y = 0;
        for ($r = 0; $r < $rows; $r++) {
            $x = 0;
            for ($c = 0; $c < $cols; $c++) {
                // Dark enough that no part of a cell could be mistaken for paper.
                $colour = imagecolorallocate($im, 20 + $r * 30, 40 + $c * 30, 60);
                imagefilledrectangle($im, $x, $y, $x + $widths[$c] - 1, $y + $cellH - 1, $colour);
                $x += $widths[$c] + $gutter;
            }
            $y += $cellH + $gutter;
        }

        ob_start();
        imagepng($im);
        $bytes = (string) ob_get_clean();
        imagedestroy($im);

        return $bytes;
    }

    public function test_it_cuts_a_regular_grid_into_its_cells(): void
    {
        $cells = $this->slicer->slice($this->sheet(3, 3), 3, 3);

        $this->assertCount(9, $cells);

        foreach ($cells as $png) {
            [$w, $h] = getimagesizefromstring($png);
            $this->assertSame(300, $w);
            $this->assertSame(200, $h);
        }
    }

    public function test_cells_come_back_in_reading_order(): void
    {
        // The cell list is the only thing tying a cell to a lesson, so the
        // order it comes back in is the whole contract.
        $cells = $this->slicer->slice($this->sheet(3, 2), 3, 2);

        $corners = array_map(function (string $png) {
            $im = imagecreatefromstring($png);
            $rgb = imagecolorat($im, 5, 5);
            imagedestroy($im);

            return [($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF];
        }, $cells);

        // Red channel encodes the row, green the column, in the fixture above.
        $this->assertSame(
            [[20, 40], [20, 70], [20, 100], [50, 40], [50, 70], [50, 100]],
            $corners,
        );
    }

    public function test_it_cuts_on_the_real_seams_not_on_exact_thirds(): void
    {
        // Measured from an actual sheet: columns came back 1077, 1087 and 1086
        // wide. Dividing by three shaves a strip off one scene and leaves a
        // white stripe down the next, on every sheet, for ever.
        $cells = $this->slicer->slice(
            $this->sheet(3, 1, cellW: 300, colWidths: [290, 310, 300]),
            3,
            1,
        );

        $widths = array_map(fn ($png) => getimagesizefromstring($png)[0], $cells);
        $this->assertSame([290, 310, 300], $widths);
    }

    public function test_a_sheet_that_is_not_the_ordered_grid_is_refused(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Nothing was cut');

        // Four big panels where nine were ordered. Cutting this anyway is how
        // nine lessons get fragments of the wrong pictures.
        $this->slicer->slice($this->sheet(2, 2), 3, 3);
    }

    public function test_an_unreadable_sheet_is_refused(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->slicer->slice('not an image at all', 3, 3);
    }

    #[DataProvider('ratios')]
    public function test_a_cell_is_trimmed_to_the_shape_its_brief_asked_for(string $ratio, float $expected): void
    {
        // The client draws this artwork in a fixed-ratio box. Whatever does not
        // match is cropped there instead - off-centre and invisibly - so it is
        // taken evenly off both sides here, where the subject stays centred.
        $cells = $this->slicer->slice($this->sheet(2, 2, cellW: 400, cellH: 400), 2, 2, $ratio);

        [$w, $h] = getimagesizefromstring($cells[0]);
        $this->assertEqualsWithDelta($expected, $w / $h, 0.01);
    }

    public static function ratios(): array
    {
        return [
            'four by three' => ['4:3', 4 / 3],
            'sixteen by nine' => ['16:9', 16 / 9],
            'square' => ['1:1', 1.0],
        ];
    }

    public function test_no_ratio_leaves_the_cell_exactly_as_cut(): void
    {
        $cells = $this->slicer->slice($this->sheet(2, 2, cellW: 400, cellH: 300), 2, 2, null);

        [$w, $h] = getimagesizefromstring($cells[0]);
        $this->assertSame([400, 300], [$w, $h]);
    }
}
