<?php

namespace App\Console\Commands;

use App\Models\MediaAsset;
use Illuminate\Console\Command;

/**
 * Fill in how big each media file is and how long it runs.
 *
 * `media_assets` has had `bytes` and `duration_ms` since the schema was
 * written, and the audio importer never set either: all 1,162 recordings from
 * the books arrived with both null. So the player cannot show a track's length
 * before it is played, a session's time estimate ignores the listening in it,
 * and nothing can tell a forty-second dialogue from a four-second word.
 *
 * Duration is read from the MP3 itself rather than shelled out to ffprobe,
 * which is not installed everywhere this runs. These recordings are constant
 * bit rate, so the first frame header gives the rate and the file size gives
 * the rest; a variable-rate file is detected by its Xing header and its frame
 * count used instead.
 */
class MeasureMedia extends Command
{
    protected $signature = 'media:measure
        {--force : re-measure files that already carry a duration}';

    protected $description = 'Record the size and running time of every stored media file';

    /** MPEG-1 Layer III bit rates, indexed by the header nibble. */
    private const BITRATES_V1 = [
        0, 32, 40, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320, 0,
    ];

    /** MPEG-2 and 2.5 Layer III bit rates. */
    private const BITRATES_V2 = [
        0, 8, 16, 24, 32, 40, 48, 56, 64, 80, 96, 112, 128, 144, 160, 0,
    ];

    private const SAMPLE_RATES = [
        3 => [44100, 48000, 32000, 0],   // MPEG-1
        2 => [22050, 24000, 16000, 0],   // MPEG-2
        0 => [11025, 12000, 8000, 0],    // MPEG-2.5
    ];

    public function handle(): int
    {
        $query = MediaAsset::query()->whereNull('deleted_at');
        if (! $this->option('force')) {
            $query->where(fn ($q) => $q->whereNull('bytes')->orWhereNull('duration_ms'));
        }

        $total = (clone $query)->count();
        if ($total === 0) {
            $this->info('Every stored file is already measured.');

            return self::SUCCESS;
        }

        $this->line("Measuring {$total} file(s)…");
        $bar = $this->output->createProgressBar($total);

        $sized = 0;
        $timed = 0;
        $missing = 0;

        $query->orderBy('id')->chunkById(200, function ($assets) use (&$sized, &$timed, &$missing, $bar) {
            foreach ($assets as $asset) {
                $bar->advance();

                $path = $this->resolve($asset);
                if ($path === null) {
                    $missing++;

                    continue;
                }

                $updates = ['bytes' => filesize($path) ?: null];
                $sized++;

                if (str_starts_with((string) $asset->mime, 'audio/') || str_ends_with($path, '.mp3')) {
                    $ms = $this->durationMs($path);
                    if ($ms !== null) {
                        $updates['duration_ms'] = $ms;
                        $timed++;
                    }
                }

                $asset->update($updates);
            }
        });

        $bar->finish();
        $this->newLine(2);
        $this->line("   sized: {$sized}");
        $this->line("   timed: {$timed}");
        if ($missing > 0) {
            $this->warn("   file not found on disk: {$missing}");
        }

        $totalMs = (int) MediaAsset::whereNotNull('duration_ms')->sum('duration_ms');
        if ($totalMs > 0) {
            $this->line('   total running time: '.round($totalMs / 3600000, 1).' hours');
        }

        return self::SUCCESS;
    }

    /**
     * Where the file actually is.
     *
     * The source recordings are stored with a repository-relative path, not a
     * disk-relative one, so the ordinary storage path misses them.
     */
    private function resolve(MediaAsset $asset): ?string
    {
        foreach ([
            storage_path('app/'.$asset->path),
            storage_path('app/private/'.$asset->path),
            base_path('../'.$asset->path),
            base_path($asset->path),
        ] as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Running time in milliseconds, or null if this is not readable as MP3.
     */
    public function durationMs(string $path): ?int
    {
        $size = filesize($path);
        $handle = @fopen($path, 'rb');
        if ($handle === false || $size === false) {
            return null;
        }

        try {
            $offset = $this->skipId3($handle);
            $header = $this->firstFrameHeader($handle, $offset, $size);
            if ($header === null) {
                return null;
            }

            [$frameOffset, $bitrate, $sampleRate, $samplesPerFrame] = $header;

            // A Xing or VBR header carries the real frame count, which is the
            // only honest way to time a variable-rate file.
            $frames = $this->vbrFrameCount($handle, $frameOffset);
            if ($frames !== null && $sampleRate > 0) {
                return (int) round($frames * $samplesPerFrame / $sampleRate * 1000);
            }

            if ($bitrate <= 0) {
                return null;
            }

            $audioBytes = $size - $frameOffset;

            return (int) round($audioBytes * 8 / ($bitrate * 1000) * 1000);
        } finally {
            fclose($handle);
        }
    }

    /** Past the ID3v2 tag, if there is one. */
    private function skipId3($handle): int
    {
        rewind($handle);
        $tag = fread($handle, 10);
        if ($tag === false || strlen($tag) < 10 || substr($tag, 0, 3) !== 'ID3') {
            return 0;
        }

        // Syncsafe integer: seven bits per byte.
        $bytes = unpack('C4', substr($tag, 6, 4));

        return 10 + (($bytes[1] << 21) | ($bytes[2] << 14) | ($bytes[3] << 7) | $bytes[4]);
    }

    /**
     * @return array{0:int,1:int,2:int,3:int}|null  offset, kbps, Hz, samples per frame
     */
    private function firstFrameHeader($handle, int $from, int $size): ?array
    {
        // A file that starts with junk still syncs within a few kilobytes.
        $limit = min($size, $from + 65536);

        for ($offset = $from; $offset < $limit; $offset++) {
            fseek($handle, $offset);
            $bytes = fread($handle, 4);
            if ($bytes === false || strlen($bytes) < 4) {
                return null;
            }

            $b = unpack('C4', $bytes);
            if ($b[1] !== 0xFF || ($b[2] & 0xE0) !== 0xE0) {
                continue;
            }

            $version = ($b[2] >> 3) & 0x03;      // 3 = MPEG-1, 2 = MPEG-2, 0 = 2.5
            $layer = ($b[2] >> 1) & 0x03;        // 1 = Layer III
            $bitrateIndex = ($b[3] >> 4) & 0x0F;
            $sampleIndex = ($b[3] >> 2) & 0x03;

            if ($layer !== 1 || $version === 1 || $bitrateIndex === 0 || $bitrateIndex === 0x0F) {
                continue;
            }
            if (! isset(self::SAMPLE_RATES[$version][$sampleIndex])) {
                continue;
            }

            $sampleRate = self::SAMPLE_RATES[$version][$sampleIndex];
            if ($sampleRate === 0) {
                continue;
            }

            $bitrate = $version === 3
                ? self::BITRATES_V1[$bitrateIndex]
                : self::BITRATES_V2[$bitrateIndex];

            return [$offset, $bitrate, $sampleRate, $version === 3 ? 1152 : 576];
        }

        return null;
    }

    /** The frame count a Xing/Info/VBRI header declares, if present. */
    private function vbrFrameCount($handle, int $frameOffset): ?int
    {
        // Xing sits after the side information; scanning the frame's first
        // 200 bytes finds it without decoding the channel mode.
        fseek($handle, $frameOffset);
        $frame = fread($handle, 200);
        if ($frame === false) {
            return null;
        }

        foreach (['Xing', 'Info'] as $marker) {
            $at = strpos($frame, $marker);
            if ($at === false) {
                continue;
            }

            $flags = substr($frame, $at + 4, 4);
            if (strlen($flags) < 4) {
                continue;
            }

            $hasFrames = (unpack('N', $flags)[1] & 0x01) === 0x01;
            if (! $hasFrames) {
                continue;
            }

            $count = substr($frame, $at + 8, 4);

            return strlen($count) === 4 ? unpack('N', $count)[1] : null;
        }

        return null;
    }
}
