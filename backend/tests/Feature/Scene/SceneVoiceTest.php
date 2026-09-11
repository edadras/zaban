<?php

namespace Tests\Feature\Scene;

use App\Models\Language;
use App\Models\MediaAsset;
use App\Models\Scene;
use App\Models\SceneBeat;
use App\Services\Scene\AudioSegmenter;
use App\Services\Scene\SceneVoiceService;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Cutting a real recording into lines.
 *
 * The test builds an actual audio file with tones and gaps rather than mocking
 * the segmenter, because the thing being tested is whether ffmpeg's silence
 * report is read correctly - and a fake of that proves nothing.
 *
 * The behaviour that matters most here is the refusal. Binding line four to
 * utterance three would give a learner a scene where every voice is one line
 * out, and they would have no way to tell that the software was wrong rather
 * than them.
 */
class SceneVoiceTest extends TestCase
{
    use RefreshDatabase;

    private string $file;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);

        if (! app(AudioSegmenter::class)->isAvailable()) {
            $this->markTestSkipped('ffmpeg is not installed on this host.');
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->file) && is_file($this->file)) {
            @unlink($this->file);
        }
        parent::tearDown();
    }

    /**
     * A recording of `$count` "utterances", each a tone, with a second of
     * silence between them.
     */
    private function recording(int $count, array $lengths = []): MediaAsset
    {
        $parts = [];
        for ($i = 0; $i < $count; $i++) {
            $seconds = $lengths[$i] ?? 1.2;
            $parts[] = "sine=frequency=320:duration={$seconds}";
            if ($i < $count - 1) {
                $parts[] = 'anullsrc=duration=1';
            }
        }

        $inputs = '';
        $labels = '';
        foreach ($parts as $index => $part) {
            $inputs .= "{$part}[p{$index}];";
            $labels .= "[p{$index}]";
        }

        $this->file = storage_path('app/scene-test-'.uniqid().'.wav');

        $process = new Process([
            'ffmpeg', '-y', '-loglevel', 'error',
            '-filter_complex', $inputs.$labels.'concat=n='.count($parts).':v=0:a=1[out]',
            '-map', '[out]', '-ar', '22050', '-ac', '1', $this->file,
        ]);
        $process->setTimeout(120);
        $process->run();

        $this->assertTrue($process->isSuccessful(), 'The test recording could not be built: '.$process->getErrorOutput());

        return MediaAsset::create([
            'disk' => 'local',
            'path' => 'scene-test.wav',
            'type' => 'audio',
            'mime' => 'audio/wav',
            'origin' => 'ingested',
            'copyright_status' => 'owned',
        ]);
    }

    private function scene(int $lines): Scene
    {
        $scene = Scene::create([
            'slug' => 'voice-test-'.$lines,
            'language_id' => Language::where('code', 'en')->value('id'),
            'title' => 'Voice test',
            'environment' => 'clinic',
            'status' => 'published',
            'cast' => [
                ['role' => 'a', 'name' => 'A', 'playable' => false],
                ['role' => 'b', 'name' => 'B', 'playable' => true],
            ],
        ]);

        for ($i = 1; $i <= $lines; $i++) {
            SceneBeat::create([
                'scene_id' => $scene->id,
                'position' => $i,
                'role' => $i % 2 ? 'a' : 'b',
                'text' => 'This is line number '.$i.'.',
                'interaction' => 'watch',
            ]);
        }

        return $scene->load('beats');
    }

    public function test_a_recording_is_cut_into_one_window_per_line(): void
    {
        $scene = $this->scene(4);
        $recording = $this->recording(4);

        $result = app(SceneVoiceService::class)->bindRecording($scene, $this->localise($recording));

        $this->assertTrue($result['ok'], $result['reason'] ?? '');
        $this->assertSame(4, $result['bound']);

        $beats = $scene->beats()->get();
        foreach ($beats as $beat) {
            $this->assertSame($recording->id, $beat->audio_media_asset_id);
            $this->assertNotNull($beat->audio_start_ms);
            $this->assertGreaterThan($beat->audio_start_ms, $beat->audio_end_ms);
            $this->assertSame('silence_segmentation', $beat->audio_method);
        }

        // In order, and not overlapping: line two starts after line one ends.
        $windows = $beats->sortBy('position')->values();
        for ($i = 1; $i < $windows->count(); $i++) {
            $this->assertGreaterThanOrEqual(
                $windows[$i - 1]->audio_end_ms,
                $windows[$i]->audio_start_ms,
            );
        }
    }

    public function test_a_machine_cut_line_is_never_marked_as_checked(): void
    {
        $scene = $this->scene(3);
        app(SceneVoiceService::class)->bindRecording($scene, $this->localise($this->recording(3)));

        $this->assertSame(
            ['pending'],
            $scene->beats()->get()->pluck('audio_review_status')->unique()->values()->all(),
        );
    }

    public function test_a_recording_with_too_few_utterances_binds_nothing(): void
    {
        $scene = $this->scene(5);
        $recording = $this->recording(3);

        $result = app(SceneVoiceService::class)->bindRecording($scene, $this->localise($recording));

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('3 utterances', $result['reason']);
        $this->assertNull($scene->beats()->first()->audio_media_asset_id);
    }

    public function test_a_speaker_pausing_mid_sentence_is_repaired_rather_than_refused(): void
    {
        // Six bursts of sound for four lines: two of the lines were said with a
        // pause in the middle.
        $scene = $this->scene(4);
        $recording = $this->recording(6);

        $result = app(SceneVoiceService::class)->bindRecording($scene, $this->localise($recording));

        $this->assertTrue($result['ok'], $result['reason'] ?? '');
        $this->assertSame(6, $result['segments']);
        $this->assertSame(4, $result['bound']);
    }

    public function test_a_dry_run_reports_without_writing(): void
    {
        $scene = $this->scene(4);
        $result = app(SceneVoiceService::class)->bindRecording($scene, $this->localise($this->recording(4)), null, false);

        $this->assertTrue($result['ok']);
        $this->assertSame(0, $result['bound']);
        $this->assertNull($scene->beats()->first()->audio_media_asset_id);
    }

    /** Point the asset at the temporary file this test actually built. */
    private function localise(MediaAsset $asset): MediaAsset
    {
        $asset->forceFill(['path' => basename($this->file)])->save();

        return $asset->fresh();
    }
}
