<?php

namespace Tests\Feature\Media;

use App\Models\CefrLevel;
use App\Models\Course;
use App\Models\CourseVersion;
use App\Models\Language;
use App\Models\Lesson;
use App\Models\MediaAsset;
use App\Models\MediaBrief;
use App\Models\Module;
use App\Models\Unit;
use App\Services\Media\GeneratedMediaImporter;
use App\Services\Media\SheetComposer;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Nine lessons' artwork out of one generation.
 *
 * A generation costs the same whether it returns one scene or nine, so a sheet
 * is six times cheaper per lesson than rendering them one at a time. What has
 * to hold for that to be worth anything is the bookkeeping: cell three of the
 * sheet must reach lesson three's brief and no other. There is no signal in the
 * image saying which cell belongs to which lesson - only the order they were
 * asked for in and the order they come back in - so these tests hold that
 * ordering, and hold the all-or-nothing rule that stops a misread sheet being
 * spread silently across nine lessons.
 */
class SheetCompositionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        Storage::fake('local');
    }

    private ?Unit $unit = null;

    private int $position = 0;

    /** A real lesson: the importer attaches the artwork to one, so a stub will not do. */
    private function lesson(int $n): Lesson
    {
        if ($this->unit === null) {
            $level = CefrLevel::where('code', 'A2')->firstOrFail();
            $course = Course::create([
                'language_id' => Language::where('code', 'en')->value('id'),
                'title' => 'Sheet course', 'slug' => 'sheet-'.uniqid(),
                'from_cefr_level_id' => $level->id, 'to_cefr_level_id' => $level->id,
                'is_active' => true,
            ]);
            $version = CourseVersion::create([
                'course_id' => $course->id, 'version' => 1,
                'status' => 'published', 'published_at' => now(),
            ]);
            $module = Module::create([
                'course_version_id' => $version->id, 'title' => 'Sheets', 'position' => 0,
            ]);
            $this->unit = Unit::create(['module_id' => $module->id, 'title' => 'Sheets', 'position' => 1]);
        }

        return Lesson::create([
            'unit_id' => $this->unit->id, 'title' => "Lesson {$n}",
            // Its own counter: a test may build overlapping groups of briefs,
            // and position is unique within a unit.
            'position' => ++$this->position, 'status' => 'published', 'difficulty' => 0.0,
        ]);
    }

    private function brief(int $n, string $ratio = '4:3', ?string $kind = null): MediaBrief
    {
        $lesson = $this->lesson($n);

        return MediaBrief::create([
            'kind' => $kind ?? MediaBrief::KIND_LESSON_SCENE,
            'subject_type' => $lesson->getMorphClass(),
            'subject_id' => $lesson->id,
            'model' => 'gpt_image_2',
            'prompt' => "One single photograph: scene number {$n}. "
                .'Do not include any of the following: text, watermark, brand names.',
            'scene' => "Teaching context: lesson {$n}. A kitchen with {$n} chairs.",
            'aspect_ratio' => $ratio,
            'resolution' => '2k',
            'status' => MediaBrief::STATUS_PENDING,
            'request_hash' => str_pad((string) $n, 64, 'a'),
        ]);
    }

    /** @return Collection<int,MediaBrief> */
    private function briefs(int $count, string $ratio = '4:3')
    {
        return collect(range(1, $count))->map(fn (int $n) => $this->brief($n, $ratio));
    }

    /** A sheet of flat-coloured cells with white gutters, as the model returns one. */
    private function sheetImage(int $cols, int $rows, int $cellW = 320, int $cellH = 240): string
    {
        $gutter = 10;
        $im = imagecreatetruecolor($cellW * $cols + $gutter * ($cols - 1), $cellH * $rows + $gutter * ($rows - 1));
        imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255));

        for ($r = 0; $r < $rows; $r++) {
            for ($c = 0; $c < $cols; $c++) {
                imagefilledrectangle(
                    $im,
                    $c * ($cellW + $gutter),
                    $r * ($cellH + $gutter),
                    $c * ($cellW + $gutter) + $cellW - 1,
                    $r * ($cellH + $gutter) + $cellH - 1,
                    imagecolorallocate($im, 10 + $r * 40, 20 + $c * 40, 90),
                );
            }
        }

        ob_start();
        imagepng($im);
        $bytes = (string) ob_get_clean();
        imagedestroy($im);

        return $bytes;
    }

    // ------------------------------------------------------------- composing

    public function test_the_sheet_is_asked_for_in_the_shape_its_cells_need(): void
    {
        $composer = app(SheetComposer::class);

        // Nine 4:3 cells laid 3 across and 3 down is a 4:3 sheet; nine 16:9
        // cells is 16:9. Get this wrong and the model either distorts every
        // scene or leaves bands of blank paper the slicer then cuts into.
        $this->assertSame('4:3', $composer->sheetRatio('4:3', 9));
        $this->assertSame('16:9', $composer->sheetRatio('16:9', 9));

        // Four across, three down, of 4:3 cells: 16 wide by 9 tall.
        $this->assertSame('16:9', $composer->sheetRatio('4:3', 12));

        // Square cards pack into a square sheet.
        $this->assertSame('1:1', $composer->sheetRatio('1:1', 16));
    }

    public function test_it_numbers_the_scenes_in_the_order_the_cells_are_read(): void
    {
        $briefs = $this->briefs(9);
        $sheet = app(SheetComposer::class)->compose($briefs);

        $this->assertSame($briefs->pluck('id')->all(), $sheet['cells']);
        $this->assertStringContainsString('1. Teaching context: lesson 1.', $sheet['prompt']);
        $this->assertStringContainsString('9. Teaching context: lesson 9.', $sheet['prompt']);

        // The numbered list is the only thing holding cell to lesson, so the
        // ninth line must be the ninth lesson, not merely present somewhere.
        $this->assertLessThan(
            strpos($sheet['prompt'], '9. Teaching context'),
            strpos($sheet['prompt'], '8. Teaching context'),
        );
    }

    public function test_it_states_the_house_style_once_instead_of_nine_times(): void
    {
        $sheet = app(SheetComposer::class)->compose($this->briefs(9));

        // Nine full prompts is ten thousand characters repeating the same rules
        // nine times, and the model loses which scene belongs in which cell.
        $this->assertSame(1, substr_count($sheet['prompt'], 'Do not include any of the following'));
        $this->assertStringNotContainsString('One single photograph', $sheet['prompt']);
    }

    public function test_the_briefs_own_exclusions_survive_into_the_sheet(): void
    {
        $sheet = app(SheetComposer::class)->compose($this->briefs(9));

        // Otherwise a sheet is nine images breaking the house rules at once.
        foreach (['text', 'watermark', 'brand names'] as $banned) {
            $this->assertStringContainsString($banned, $sheet['prompt']);
        }

        // And the ones that are about being a sheet at all.
        $this->assertStringContainsString('uneven cells', $sheet['prompt']);
        $this->assertStringContainsString('a scene crossing a gutter', $sheet['prompt']);
    }

    public function test_a_sheet_does_not_forbid_itself(): void
    {
        // Every single-image brief forbids collages - it is how the scene
        // prompt stops the model returning a grid. Inheriting that here asks
        // for a grid of nine while forbidding grids, and the model resolves the
        // contradiction by ignoring whichever half it likes.
        $brief = $this->brief(1);
        $brief->update([
            'prompt' => 'A scene. Do not include any of the following: text, watermark, '
                .'collage, contact sheet, multiple panels, brand names.',
        ]);
        $briefs = collect([$brief])->concat($this->briefs(8));

        $sheet = app(SheetComposer::class)->compose($briefs);
        $exclusions = substr($sheet['prompt'], strrpos($sheet['prompt'], 'Do not include'));

        foreach (['collage', 'contact sheet', 'multiple panels'] as $shouldBeGone) {
            $this->assertStringNotContainsString($shouldBeGone, $exclusions);
        }

        // The rules that are nothing to do with layout still hold.
        $this->assertStringContainsString('watermark', $exclusions);
        $this->assertStringContainsString('brand names', $exclusions);
    }

    public function test_a_sheet_cannot_mix_cell_shapes(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $mixed = $this->briefs(8)->push($this->brief(9, '16:9'));
        app(SheetComposer::class)->compose($mixed);
    }

    public function test_an_unsupported_cell_count_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        // Seven cells has no rectangular grid; guessing one produces a sheet
        // the slicer cannot read.
        app(SheetComposer::class)->grid(7);
    }

    // -------------------------------------------------------------- exporting

    public function test_the_command_only_exports_a_full_sheet(): void
    {
        $this->briefs(4);

        // Four briefs, nine cells. Asking for nine and naming four is exactly
        // how a sheet comes back as something the slicer refuses.
        $this->artisan('media:sheet', ['--cells' => 9, '--sheets' => 1])->assertExitCode(0);

        // Nothing was claimed, because nothing was exported.
        $this->assertSame(0, MediaBrief::where('status', MediaBrief::STATUS_GENERATING)->count());
    }

    public function test_claiming_marks_every_cell_at_once(): void
    {
        $this->briefs(9);

        $this->artisan('media:sheet', ['--cells' => 9, '--claim' => true])->assertExitCode(0);

        // A half-claimed sheet would hand the same lessons to a second runner.
        $this->assertSame(9, MediaBrief::where('status', MediaBrief::STATUS_GENERATING)->count());
    }

    // -------------------------------------------------------------- importing

    public function test_a_sheet_becomes_nine_attached_lesson_images(): void
    {
        $briefs = $this->briefs(9);
        $file = Storage::disk('local')->path('sheet.png');
        @mkdir(dirname($file), 0777, true);
        file_put_contents($file, $this->sheetImage(3, 3));

        $out = app(GeneratedMediaImporter::class)->importSheet([
            'file' => 'sheet.png',
            'cols' => 3,
            'rows' => 3,
            'cells' => $briefs->pluck('id')->all(),
            'prompt' => 'a contact sheet',
        ], dirname($file));

        $this->assertSame(9, $out['imported']);
        $this->assertSame(0, $out['failed']);

        foreach ($briefs as $b) {
            $this->assertSame(MediaBrief::STATUS_IMPORTED, $b->fresh()->status);
            $this->assertNotNull($b->fresh()->media_asset_id);
        }

        // Nine separate pictures, not one stored nine times: the importer
        // de-duplicates by checksum, so identical cells would collapse into a
        // single asset and eight lessons would quietly share one image.
        $this->assertSame(9, MediaAsset::count());
    }

    public function test_each_cell_records_which_sheet_it_was_cut_from(): void
    {
        $briefs = $this->briefs(9);
        $file = Storage::disk('local')->path('sheet.png');
        @mkdir(dirname($file), 0777, true);
        file_put_contents($file, $this->sheetImage(3, 3));

        app(GeneratedMediaImporter::class)->importSheet([
            'file' => 'sheet.png',
            'cols' => 3,
            'rows' => 3,
            'cells' => $briefs->pluck('id')->all(),
            'prompt' => 'a contact sheet of nine kitchens',
        ], dirname($file));

        // Nine lessons pointing at one generation is only traceable if each
        // says which cell of which sheet it was.
        $meta = $briefs[2]->fresh()->mediaAsset->metadata;
        $this->assertStringContainsString('Cell 3 of a 3x3 contact sheet', $meta['prompt']);
        $this->assertStringContainsString('a contact sheet of nine kitchens', $meta['prompt']);
        $this->assertNotNull($meta['brief_prompt']);
    }

    public function test_a_misread_sheet_imports_nothing_at_all(): void
    {
        $briefs = $this->briefs(9);
        $file = Storage::disk('local')->path('sheet.png');
        @mkdir(dirname($file), 0777, true);
        // Came back as four big panels rather than nine.
        file_put_contents($file, $this->sheetImage(2, 2));

        $out = app(GeneratedMediaImporter::class)->importSheet([
            'file' => 'sheet.png',
            'cols' => 3,
            'rows' => 3,
            'cells' => $briefs->pluck('id')->all(),
        ], dirname($file));

        $this->assertSame(0, $out['imported']);
        $this->assertSame(9, $out['failed']);
        $this->assertNotEmpty($out['errors']);

        // The alternative is nine lessons showing slivers of each other's
        // artwork, which nobody notices until a learner does.
        foreach ($briefs as $b) {
            $this->assertSame(MediaBrief::STATUS_PENDING, $b->fresh()->status);
            $this->assertNull($b->fresh()->media_asset_id);
        }
    }

    public function test_a_cell_list_that_does_not_match_the_grid_imports_nothing(): void
    {
        $briefs = $this->briefs(6);
        $file = Storage::disk('local')->path('sheet.png');
        @mkdir(dirname($file), 0777, true);
        file_put_contents($file, $this->sheetImage(3, 3));

        // Nine cells cut, six briefs named: which six of the nine? There is no
        // answer, so nothing is guessed.
        $out = app(GeneratedMediaImporter::class)->importSheet([
            'file' => 'sheet.png',
            'cols' => 3,
            'rows' => 3,
            'cells' => $briefs->pluck('id')->all(),
        ], dirname($file));

        $this->assertSame(0, $out['imported']);
        $this->assertSame(6, $out['failed']);
    }

    public function test_re_importing_a_sheet_does_not_duplicate_anything(): void
    {
        $briefs = $this->briefs(9);
        $file = Storage::disk('local')->path('sheet.png');
        @mkdir(dirname($file), 0777, true);
        file_put_contents($file, $this->sheetImage(3, 3));

        $sheet = [
            'file' => 'sheet.png', 'cols' => 3, 'rows' => 3,
            'cells' => $briefs->pluck('id')->all(),
        ];

        app(GeneratedMediaImporter::class)->importSheet($sheet, dirname($file));
        $second = app(GeneratedMediaImporter::class)->importSheet($sheet, dirname($file));

        // Runs this long get interrupted and re-run; a second pass must cost
        // nothing and change nothing.
        $this->assertSame(0, $second['imported']);
        $this->assertSame(9, $second['skipped']);
        $this->assertSame(9, MediaAsset::count());
    }

    public function test_a_sheet_asks_for_the_look_the_cards_on_it_need(): void
    {
        $composer = app(SheetComposer::class);

        $scenes = $composer->compose($this->briefs(4))['prompt'];
        $cards = $composer->compose(collect(range(1, 4))->map(
            fn (int $n) => $this->brief($n, '4:3', MediaBrief::KIND_VOCABULARY_CARD),
        ))['prompt'];

        // A vocabulary card is one object on plain paper. Asked for in the
        // documentary style a lesson scene wants, the object is the part that
        // comes back out of focus.
        $this->assertStringContainsString('documentary photography', $scenes);
        $this->assertStringNotContainsString('documentary photography', $cards);
        $this->assertStringContainsString('product-photography lighting', $cards);
        $this->assertStringContainsString('plain', $cards);
    }

    public function test_the_look_is_stated_once_not_once_per_cell(): void
    {
        $prompt = app(SheetComposer::class)->compose(collect(range(1, 16))->map(
            fn (int $n) => $this->brief($n, '4:3', MediaBrief::KIND_VOCABULARY_CARD),
        ))['prompt'];

        // Sixteen repetitions of the lighting would crowd out the sixteen
        // words the sheet is actually for.
        $this->assertSame(1, substr_count($prompt, 'product-photography lighting'));
    }

    public function test_a_sheet_will_not_mix_kinds_of_brief(): void
    {
        $mixed = collect([
            $this->brief(1),
            $this->brief(2, '4:3', MediaBrief::KIND_VOCABULARY_CARD),
            $this->brief(3),
            $this->brief(4),
        ]);

        // There is one house style per sheet and it is chosen by kind, so a
        // mixed sheet has no style to ask for - better to refuse than to
        // silently give half the cells the wrong one.
        $this->expectException(\InvalidArgumentException::class);
        app(SheetComposer::class)->compose($mixed);
    }
}
