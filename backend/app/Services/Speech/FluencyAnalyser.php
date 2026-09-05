<?php

namespace App\Services\Speech;

/**
 * Fluency signals measured from word timings (spec 21).
 *
 * Every number here comes from the timeline the speech provider returned. When a
 * provider gives no timings there is nothing to measure, and the analyser says
 * so instead of estimating from word count alone - a "speech rate" computed
 * without a duration is not a speech rate.
 */
class FluencyAnalyser
{
    /** A silence only counts as a pause above this length; shorter gaps are ordinary co-articulation. */
    public const PAUSE_THRESHOLD_MS = 250;

    /** Comfortable conversational range for English, in words per minute. */
    public const RATE_TARGET_MIN = 110.0;
    public const RATE_TARGET_MAX = 170.0;

    public function __construct(private TextTokeniser $tokeniser) {}

    /**
     * @param  array<int,array{norm:string,start_ms:?int,end_ms:?int}>  $words
     * @return array{
     *     speech_rate_wpm:?float, pause_count:?int, total_pause_ms:?int,
     *     filler_count:int, speaking_ms:?int, articulation_rate_wpm:?float, reason:?string
     * }
     */
    public function measure(array $words, ?int $durationMs = null): array
    {
        $fillers = $this->tokeniser->countFillers($words);
        $timed = array_values(array_filter(
            $words,
            fn ($w) => isset($w['start_ms'], $w['end_ms']) && $w['end_ms'] >= $w['start_ms'],
        ));

        if (count($timed) < 2) {
            return [
                'speech_rate_wpm' => null,
                'pause_count' => null,
                'total_pause_ms' => null,
                'filler_count' => $fillers,
                'speaking_ms' => null,
                'articulation_rate_wpm' => null,
                'reason' => 'The speech provider returned no usable word timings, so fluency was not measured.',
            ];
        }

        $span = (int) ($timed[count($timed) - 1]['end_ms'] - $timed[0]['start_ms']);
        $elapsed = $durationMs && $durationMs > 0 ? $durationMs : $span;
        if ($elapsed <= 0) {
            $elapsed = $span;
        }

        $pauseCount = 0;
        $pauseMs = 0;
        for ($i = 1, $n = count($timed); $i < $n; $i++) {
            $gap = (int) ($timed[$i]['start_ms'] - $timed[$i - 1]['end_ms']);
            if ($gap >= self::PAUSE_THRESHOLD_MS) {
                $pauseCount++;
                $pauseMs += $gap;
            }
        }

        $wordCount = count($words);
        $rate = $elapsed > 0 ? round($wordCount / ($elapsed / 60000), 2) : null;

        // Articulation rate excludes pause time: it separates "thinking slowly"
        // from "speaking slowly", which need different advice.
        $phonationMs = max(1, $elapsed - $pauseMs);
        $articulation = round($wordCount / ($phonationMs / 60000), 2);

        return [
            'speech_rate_wpm' => $rate,
            'pause_count' => $pauseCount,
            'total_pause_ms' => $pauseMs,
            'filler_count' => $fillers,
            'speaking_ms' => $elapsed,
            'articulation_rate_wpm' => $articulation,
            'reason' => null,
        ];
    }

    /**
     * 0-100 fluency score from the measured signals, or null when nothing was measured.
     *
     * @param  array{speech_rate_wpm:?float,pause_count:?int,total_pause_ms:?int,filler_count:int,speaking_ms:?int}  $m
     */
    public function score(array $m, int $wordCount): ?float
    {
        if ($m['speech_rate_wpm'] === null || $m['speaking_ms'] === null || $wordCount === 0) {
            return null;
        }

        $rate = (float) $m['speech_rate_wpm'];
        $distance = match (true) {
            $rate < self::RATE_TARGET_MIN => self::RATE_TARGET_MIN - $rate,
            $rate > self::RATE_TARGET_MAX => $rate - self::RATE_TARGET_MAX,
            default => 0.0,
        };
        $rateScore = $this->clamp(100 - $distance * 1.2);

        $pauseRatio = $m['speaking_ms'] > 0 ? ((int) $m['total_pause_ms']) / $m['speaking_ms'] : 0.0;
        $pauseScore = $this->clamp(100 - $pauseRatio * 250);

        $fillerRate = $m['filler_count'] / $wordCount;
        $fillerScore = $this->clamp(100 - $fillerRate * 400);

        return round($rateScore * 0.4 + $pauseScore * 0.4 + $fillerScore * 0.2, 2);
    }

    private function clamp(float $v): float
    {
        return max(0.0, min(100.0, $v));
    }
}
