<?php

namespace App\Services\Media;

/**
 * Cuts a returned contact sheet back into the lesson images it was asked for.
 *
 * The obvious implementation - divide the width by three - is the wrong one.
 * These models honour "equal cells" closely but not exactly: measured on a real
 * 3x3 sheet the columns came back 1077, 1087 and 1086 pixels wide with gutters
 * of 19 to 20. Cutting at exact thirds would shave a slice off one scene and
 * leave a white stripe down the next, on every sheet, for ever.
 *
 * So the sheet is cut on its own seams. The gutters were asked for as white
 * bands running the full width or height, and that is exactly what makes them
 * findable: a gutter row is one where the darkest pixel anywhere along it is
 * still near white. Nothing inside a photograph does that.
 *
 * If the seams do not describe the grid that was ordered, nothing is cut. A
 * sheet that came back as four big panels instead of nine would otherwise be
 * carved into nine pieces of the wrong pictures and attached to nine lessons,
 * which is far worse than a failed round: it is wrong artwork that nobody is
 * told about.
 */
class SheetSlicer
{
    /**
     * How light a line must be, at its darkest point, to count as a gutter.
     *
     * Below this and bright sky or a white wall filling a whole row would be
     * read as a seam; above it and a gutter that picked up a little compression
     * noise is missed. 225 clears both on the sheets measured.
     */
    private const GUTTER_FLOOR = 225;

    /** A content band narrower than this fraction of the sheet is not a cell. */
    private const MIN_CELL = 0.05;

    /**
     * @return array<int,string> PNG bytes, in reading order
     *
     * @throws \RuntimeException when the sheet is not the grid that was ordered
     */
    public function slice(string $bytes, int $cols, int $rows, ?string $cellRatio = null): array
    {
        $sheet = @imagecreatefromstring($bytes);

        if (! $sheet) {
            throw new \RuntimeException('The sheet is not a readable image.');
        }

        try {
            $w = imagesx($sheet);
            $h = imagesy($sheet);

            $colSpans = $this->spans($this->gutterLines($sheet, $w, $h, vertical: true), $w);
            $rowSpans = $this->spans($this->gutterLines($sheet, $w, $h, vertical: false), $h);

            if (count($colSpans) !== $cols || count($rowSpans) !== $rows) {
                throw new \RuntimeException(sprintf(
                    'The sheet came back as %dx%d, not the %dx%d that was ordered. Nothing was cut.',
                    count($colSpans),
                    count($rowSpans),
                    $cols,
                    $rows,
                ));
            }

            $out = [];

            foreach ($rowSpans as [$top, $bottom]) {
                foreach ($colSpans as [$left, $right]) {
                    $out[] = $this->cut($sheet, $this->fit([$left, $top, $right, $bottom], $cellRatio));
                }
            }

            return $out;
        } finally {
            imagedestroy($sheet);
        }
    }

    /**
     * Rows (or columns) that are near-white the whole way across.
     *
     * Read from a downsampled copy: a 3312x2480 sheet is eight million pixels,
     * and at full size this is slow enough in PHP to be felt on every import.
     * A gutter twenty pixels wide survives an eight-fold reduction easily.
     *
     * @return array<int,bool> indexed in the ORIGINAL image's coordinates
     */
    private function gutterLines(\GdImage $sheet, int $w, int $h, bool $vertical): array
    {
        $scale = max(1, (int) floor(min($w, $h) / 400));
        $sw = max(1, intdiv($w, $scale));
        $sh = max(1, intdiv($h, $scale));

        $small = imagecreatetruecolor($sw, $sh);
        imagecopyresampled($small, $sheet, 0, 0, 0, 0, $sw, $sh, $w, $h);

        try {
            $outer = $vertical ? $sw : $sh;
            $inner = $vertical ? $sh : $sw;
            $lines = [];

            for ($i = 0; $i < $outer; $i++) {
                $darkest = 255;

                for ($j = 0; $j < $inner; $j++) {
                    $rgb = imagecolorat($small, $vertical ? $i : $j, $vertical ? $j : $i);
                    $min = min(($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF);

                    if ($min < $darkest) {
                        $darkest = $min;

                        if ($darkest <= self::GUTTER_FLOOR) {
                            break;
                        }
                    }
                }

                // Back to full-size coordinates; one small pixel is $scale of them.
                for ($k = 0; $k < $scale; $k++) {
                    $lines[$i * $scale + $k] = $darkest > self::GUTTER_FLOOR;
                }
            }

            return $lines + array_fill(0, $vertical ? $w : $h, false);
        } finally {
            imagedestroy($small);
        }
    }

    /**
     * The content bands between the gutters.
     *
     * The sheet's outer edge is a boundary too, and is usually content rather
     * than gutter - the cells run to the paper's edge - so the first band starts
     * at zero and the last ends at the far side.
     *
     * @param  array<int,bool>  $isGutter
     * @return array<int,array{0:int,1:int}>
     */
    private function spans(array $isGutter, int $total): array
    {
        ksort($isGutter);
        $spans = [];
        $start = 0;
        $inGutter = false;

        for ($i = 0; $i < $total; $i++) {
            $gutter = $isGutter[$i] ?? false;

            if ($gutter && ! $inGutter) {
                if ($i - $start >= $total * self::MIN_CELL) {
                    $spans[] = [$start, $i];
                }
                $inGutter = true;
            }

            if (! $gutter && $inGutter) {
                $start = $i;
                $inGutter = false;
            }
        }

        if (! $inGutter && $total - $start >= $total * self::MIN_CELL) {
            $spans[] = [$start, $total];
        }

        return $spans;
    }

    /**
     * Trim a cell to the exact shape its brief asked for.
     *
     * Cells come back a percent or two off square, and the client draws this
     * artwork in a fixed-ratio box: whatever does not match is cropped there
     * instead, invisibly and off-centre. Better to take it evenly off both
     * sides here, where the subject stays centred.
     *
     * @param  array{0:int,1:int,2:int,3:int}  $box
     * @return array{0:int,1:int,2:int,3:int}
     */
    private function fit(array $box, ?string $ratio): array
    {
        if ($ratio === null || ! str_contains($ratio, ':')) {
            return $box;
        }

        [$left, $top, $right, $bottom] = $box;
        [$rw, $rh] = array_map('intval', explode(':', $ratio));

        if ($rw <= 0 || $rh <= 0) {
            return $box;
        }

        $want = $rw / $rh;
        $w = $right - $left;
        $h = $bottom - $top;

        if ($w / $h > $want) {
            $new = (int) round($h * $want);
            $left += intdiv($w - $new, 2);
            $right = $left + $new;
        } else {
            $new = (int) round($w / $want);
            $top += intdiv($h - $new, 2);
            $bottom = $top + $new;
        }

        return [$left, $top, $right, $bottom];
    }

    /** @param array{0:int,1:int,2:int,3:int} $box */
    private function cut(\GdImage $sheet, array $box): string
    {
        [$left, $top, $right, $bottom] = $box;
        $cell = imagecrop($sheet, [
            'x' => $left,
            'y' => $top,
            'width' => $right - $left,
            'height' => $bottom - $top,
        ]);

        if (! $cell) {
            throw new \RuntimeException('Could not cut a cell out of the sheet.');
        }

        try {
            ob_start();
            imagepng($cell);

            return (string) ob_get_clean();
        } finally {
            imagedestroy($cell);
        }
    }
}
