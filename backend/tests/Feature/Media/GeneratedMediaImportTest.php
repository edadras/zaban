<?php

namespace Tests\Feature\Media;

use App\Models\Character;
use App\Models\MediaAsset;
use App\Models\MediaBrief;
use App\Services\Media\GeneratedMediaImporter;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The import is the step that makes a generation ours: provider URLs expire, so
 * anything only referenced by URL is already lost. These tests hold that path
 * open, and hold idempotency closed - the batches this runs over are thousands
 * of files long and will be interrupted, so a re-run must not re-download or
 * duplicate what is already stored.
 */
class GeneratedMediaImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        Storage::fake('local');
    }

    private function pngBytes(): string
    {
        $im = imagecreatetruecolor(64, 64);
        ob_start();
        imagepng($im);
        $png = ob_get_clean();
        imagedestroy($im);

        // The importer rejects anything under 1KB as not-an-image, so pad the
        // fixture past that floor with a trailing comment chunk.
        return $png.str_repeat("\0", 2048);
    }

    private function briefFor(Character $character): MediaBrief
    {
        return MediaBrief::create([
            'kind' => MediaBrief::KIND_CHARACTER_PORTRAIT,
            'subject_type' => $character->getMorphClass(),
            'subject_id' => $character->id,
            'model' => 'nano_banana_pro',
            'prompt' => 'Portrait of Maya.',
            'aspect_ratio' => '1:1',
            'resolution' => '2k',
            'status' => MediaBrief::STATUS_PENDING,
            'request_hash' => str_repeat('a', 64),
        ]);
    }

    public function test_it_stores_the_file_and_links_it_to_its_subject(): void
    {
        $png = $this->pngBytes();
        Http::fake(['*' => Http::response($png, 200)]);

        $character = Character::create(['slug' => 'maya', 'name' => 'Maya']);
        $brief = $this->briefFor($character);

        $asset = app(GeneratedMediaImporter::class)->import($brief, 'https://cdn.example/x.png');

        Storage::disk('local')->assertExists($asset->path);
        $this->assertSame('generated', $asset->origin);
        $this->assertSame('owned', $asset->copyright_status);
        $this->assertSame(hash('sha256', $png), $asset->checksum);
        $this->assertSame(64, $asset->width);

        // Traceable back to the exact request that made it.
        $this->assertSame('nano_banana_pro', $asset->metadata['model']);
        $this->assertSame($brief->id, $asset->metadata['brief_id']);

        $this->assertSame(MediaBrief::STATUS_IMPORTED, $brief->fresh()->status);

        $character->refresh();
        $this->assertSame($asset->id, $character->avatar_media_asset_id);
        $this->assertSame($asset->id, $character->reference_media_asset_id);
    }

    public function test_re_importing_does_not_duplicate_or_re_download(): void
    {
        $calls = 0;
        Http::fake(function (Request $r) use (&$calls) {
            $calls++;

            return Http::response($this->pngBytes(), 200);
        });

        $character = Character::create(['slug' => 'maya', 'name' => 'Maya']);
        $brief = $this->briefFor($character);

        $first = app(GeneratedMediaImporter::class)->importMany([$brief->id => 'https://cdn.example/x.png']);
        $second = app(GeneratedMediaImporter::class)->importMany([$brief->id => 'https://cdn.example/x.png']);

        $this->assertSame(1, $first['imported']);
        $this->assertSame(1, $second['skipped']);
        $this->assertSame(0, $second['imported']);
        $this->assertSame(1, $calls, 'an already-imported brief must not be downloaded again');
        $this->assertSame(1, MediaAsset::where('origin', 'generated')->count());
    }

    public function test_identical_output_for_two_briefs_is_stored_once(): void
    {
        Http::fake(['*' => Http::response($this->pngBytes(), 200)]);

        $a = Character::create(['slug' => 'a', 'name' => 'A']);
        $b = Character::create(['slug' => 'b', 'name' => 'B']);

        $importer = app(GeneratedMediaImporter::class);
        $assetA = $importer->import($this->briefFor($a), 'https://cdn.example/1.png');
        $assetB = $importer->import($this->briefFor($b), 'https://cdn.example/2.png');

        $this->assertSame($assetA->id, $assetB->id, 'the same bytes must not be stored twice');
        $this->assertSame(1, MediaAsset::where('origin', 'generated')->count());
    }

    public function test_a_failed_download_marks_the_brief_failed_rather_than_losing_it(): void
    {
        Http::fake(['*' => Http::response('nope', 500)]);

        $character = Character::create(['slug' => 'maya', 'name' => 'Maya']);
        $brief = $this->briefFor($character);

        $out = app(GeneratedMediaImporter::class)->importMany([$brief->id => 'https://cdn.example/x.png']);

        $this->assertSame(1, $out['failed']);
        $brief->refresh();
        $this->assertSame(MediaBrief::STATUS_FAILED, $brief->status);
        $this->assertNotNull($brief->error);

        // Failed briefs stay in the render queue rather than vanishing.
        $this->assertTrue(MediaBrief::renderable()->pluck('id')->contains($brief->id));
    }
}
