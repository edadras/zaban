<?php

namespace App\Services\Scene;

use Symfony\Component\Process\Process;

/**
 * Finds where the speaking is inside a recording.
 *
 * The course's audio is one track per exercise: a two-minute file holding a
 * dozen separate utterances with pauses between them. To let a scene speak a
 * single line in the voice that actually recorded it, those utterances have to
 * be located - and locating them by the silence around them costs nothing, runs
 * offline, and does not need the recording transcribed first.
 *
 * It is measurement, not understanding. It reports where sound starts and stops
 * and nothing at all about what was said, which is why whatever uses it has to
 * check the count it gets back rather than trusting it.
 */
class AudioSegmenter
{
    public function __construct(
        private string $binary = 'ffmpeg',
        /** Anything quieter than this counts as silence. */
        private string $noise = '-35dB',
        /** A pause shorter than this is inside an utterance, not between two. */
        private float $minSilence = 0.45,
    ) {}

    public function isAvailable(): bool
    {
        $probe = new Process([$this->binary, '-version']);
        $probe->setTimeout(20);
        $probe->run();

        return $probe->isSuccessful();
    }

    /**
     * Speech segments in a file, in milliseconds.
     *
     * @return array<int,array{start:int,end:int,duration:int}>
     */
    public function segments(string $file, ?float $minSpeech = 0.35): array
    {
        $process = new Process([
            $this->binary, '-hide_banner', '-nostats', '-i', $file,
            '-af', "silencedetect=noise={$this->noise}:d={$this->minSilence}",
            '-f', 'null', '-',
        ]);
        $process->setTimeout(300);
        $process->run();

        // ffmpeg writes the filter's report to stderr even on success.
        $log = $process->getErrorOutput();

        $duration = $this->duration($log);
        if ($duration === null) {
            return [];
        }

        $silences = $this->silences($log, $duration);

        // Speech is what is left over between the silences.
        $segments = [];
        $cursor = 0.0;

        foreach ($silences as [$start, $end]) {
            if ($start - $cursor >= ($minSpeech ?? 0)) {
                $segments[] = $this->segment($cursor, $start);
            }
            $cursor = max($cursor, $end);
        }

        if ($duration - $cursor >= ($minSpeech ?? 0)) {
            $segments[] = $this->segment($cursor, $duration);
        }

        return $segments;
    }

    /**
     * Reduce a list of segments to exactly `$want` of them by joining the ones
     * separated by the shortest pauses.
     *
     * A speaker who pauses mid-sentence splits one line into two, and joining
     * the closest pair repeatedly is the cheapest repair that does not need to
     * know what was said. It only ever joins neighbours, so the order of the
     * recording is never disturbed.
     *
     * @param  array<int,array{start:int,end:int,duration:int}>  $segments
     * @return array<int,array{start:int,end:int,duration:int}>
     */
    public function fit(array $segments, int $want): array
    {
        if ($want < 1 || count($segments) <= $want) {
            return $segments;
        }

        $segments = array_values($segments);

        while (count($segments) > $want) {
            $best = null;
            $shortest = PHP_INT_MAX;

            for ($i = 0; $i < count($segments) - 1; $i++) {
                $gap = $segments[$i + 1]['start'] - $segments[$i]['end'];
                if ($gap < $shortest) {
                    $shortest = $gap;
                    $best = $i;
                }
            }

            if ($best === null) {
                break;
            }

            $joined = [
                'start' => $segments[$best]['start'],
                'end' => $segments[$best + 1]['end'],
            ];
            $joined['duration'] = $joined['end'] - $joined['start'];

            array_splice($segments, $best, 2, [$joined]);
        }

        return $segments;
    }

    private function segment(float $start, float $end): array
    {
        $startMs = (int) round($start * 1000);
        $endMs = (int) round($end * 1000);

        return ['start' => $startMs, 'end' => $endMs, 'duration' => $endMs - $startMs];
    }

    private function duration(string $log): ?float
    {
        if (! preg_match('/Duration:\s*(\d+):(\d+):(\d+(?:\.\d+)?)/', $log, $m)) {
            return null;
        }

        return ((int) $m[1] * 3600) + ((int) $m[2] * 60) + (float) $m[3];
    }

    /** @return array<int,array{0:float,1:float}> */
    private function silences(string $log, float $duration): array
    {
        preg_match_all('/silence_start:\s*(-?[\d.]+)/', $log, $starts);
        preg_match_all('/silence_end:\s*([\d.]+)/', $log, $ends);

        $out = [];
        foreach ($starts[1] as $i => $start) {
            // A trailing silence has no end line: it runs to the end of the file.
            $out[] = [max(0.0, (float) $start), (float) ($ends[1][$i] ?? $duration)];
        }

        return $out;
    }
}
