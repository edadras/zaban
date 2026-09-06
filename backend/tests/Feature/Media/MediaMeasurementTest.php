<?php

namespace Tests\Feature\Media;

use App\Console\Commands\MeasureMedia;
use Tests\TestCase;

/**
 * `bytes` and `duration_ms` were on `media_assets` from the first migration and
 * the audio importer set neither, so all 1,162 recordings from the books were
 * stored without a length. The player could not show one, and a session's time
 * estimate counted the listening in it as nothing.
 *
 * These read the repository's own recordings rather than a fixture: the point
 * is that the reader works on the files this project actually ships.
 */
class MediaMeasurementTest extends TestCase
{
    private function anyRecording(): string
    {
        $files = glob(base_path('../sources/audio/*/*.mp3')) ?: [];
        if ($files === []) {
            $files = glob(base_path('../sources/audio/*/*/*.mp3')) ?: [];
        }

        if ($files === []) {
            $this->markTestSkipped('The source recordings are not present in this checkout.');
        }

        sort($files);

        return $files[0];
    }

    public function test_it_reads_a_running_time_from_a_real_recording(): void
    {
        $ms = app(MeasureMedia::class)->durationMs($this->anyRecording());

        $this->assertNotNull($ms, 'the reader could not time a recording the project ships');
        // A unit recording is a passage read aloud: seconds, not milliseconds
        // and not an hour.
        $this->assertGreaterThan(2_000, $ms);
        $this->assertLessThan(600_000, $ms);
    }

    /**
     * The duration is derived from the bit rate and the file size, so it has to
     * agree with them: a wrong frame header shows up as an implausible rate.
     */
    public function test_the_running_time_agrees_with_the_file_size(): void
    {
        $path = $this->anyRecording();
        $ms = app(MeasureMedia::class)->durationMs($path);

        $this->assertNotNull($ms);

        $kbps = filesize($path) * 8 / ($ms / 1000) / 1000;

        $this->assertGreaterThan(24, $kbps, "implied bit rate of {$kbps} kbps is too low to be real");
        $this->assertLessThan(400, $kbps, "implied bit rate of {$kbps} kbps is too high to be real");
    }

    public function test_a_file_that_is_not_audio_is_reported_rather_than_guessed(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'notaudio');
        file_put_contents($path, str_repeat('this is not an mp3 ', 200));

        try {
            $this->assertNull(app(MeasureMedia::class)->durationMs($path));
        } finally {
            @unlink($path);
        }
    }
}
