<?php

namespace Tests\Feature\Media;

use App\Models\MediaAsset;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Lesson blocks reference media by id, so without a working resolver the entire
 * audio corpus is unreachable by the client. These tests hold that path open,
 * and hold the signature requirement closed.
 */
class MediaDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        Storage::fake('local');
    }

    private function asset(string $path = 'audio/u01.mp3', string $mime = 'audio/mpeg'): MediaAsset
    {
        Storage::disk('local')->put($path, str_repeat('a', 4096));

        return MediaAsset::create([
            'disk' => 'local', 'path' => $path, 'type' => 'audio', 'mime' => $mime,
            'bytes' => 4096, 'origin' => 'ingested', 'copyright_status' => 'owned',
        ]);
    }

    public function test_an_asset_resolves_to_a_playable_signed_url(): void
    {
        $asset = $this->asset();

        $response = $this->actingAs(User::factory()->create())
            ->getJson("/api/v1/media/{$asset->id}");

        $response->assertOk()->assertJsonStructure([
            'data' => ['id', 'type', 'mime', 'url', 'expires_in'],
        ]);

        $url = $response->json('data.url');
        $this->assertStringContainsString('signature=', $url,
            'the playback URL must be signed, not a bare public path');
        $this->assertStringContainsString('expires=', $url);
    }

    public function test_a_lesson_can_resolve_all_its_media_in_one_request(): void
    {
        $ids = collect(range(1, 5))
            ->map(fn ($i) => $this->asset("audio/u{$i}.mp3")->id)->all();

        $response = $this->actingAs(User::factory()->create())
            ->postJson('/api/v1/media/batch', ['ids' => $ids]);

        $response->assertOk()->assertJsonPath('meta.found', 5);
        foreach ($ids as $id) {
            $this->assertNotEmpty($response->json("data.{$id}.url"));
        }
    }

    public function test_streaming_without_a_valid_signature_is_refused(): void
    {
        $asset = $this->asset();

        // The signature is the authentication here, so an unsigned request must
        // fail even for a logged-in user.
        $this->actingAs(User::factory()->create())
            ->get("/api/v1/media/{$asset->id}/stream")
            ->assertForbidden();
    }

    public function test_a_signed_link_streams_the_bytes(): void
    {
        $asset = $this->asset();
        $url = $this->actingAs(User::factory()->create())
            ->getJson("/api/v1/media/{$asset->id}")->json('data.url');

        $response = $this->get($url);

        $response->assertOk();
        $this->assertSame('audio/mpeg', $response->headers->get('Content-Type'));
        $this->assertSame('bytes', $response->headers->get('Accept-Ranges'),
            'range support is what lets a learner scrub audio rather than only replay it');
    }

    public function test_a_range_request_returns_only_that_slice(): void
    {
        $asset = $this->asset();
        $url = $this->actingAs(User::factory()->create())
            ->getJson("/api/v1/media/{$asset->id}")->json('data.url');

        $response = $this->get($url, ['Range' => 'bytes=100-199']);

        $response->assertStatus(206);
        $this->assertSame('bytes 100-199/4096', $response->headers->get('Content-Range'));
        $this->assertSame('100', $response->headers->get('Content-Length'));
    }

    public function test_an_expired_link_stops_working(): void
    {
        $asset = $this->asset();
        $url = $this->actingAs(User::factory()->create())
            ->getJson("/api/v1/media/{$asset->id}")->json('data.url');

        Carbon::setTestNow(now()->addHours(2));
        $response = $this->get($url);
        Carbon::setTestNow();

        $response->assertForbidden();
    }
}
