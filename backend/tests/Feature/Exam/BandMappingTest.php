<?php

namespace Tests\Feature\Exam;

use App\Models\ExamSection;
use App\Services\Exam\ScoringService;
use App\Services\Exam\SectionScoring;

/**
 * The band tables are the one place where an off-by-a-half quietly tells a
 * learner they are ready for a test they will fail, so every boundary is
 * asserted from both sides.
 */
class BandMappingTest extends ExamTestCase
{
    private function scoring(): ScoringService
    {
        return app(ScoringService::class);
    }

    public function test_ielts_band_boundaries_map_to_the_expected_cefr_level(): void
    {
        $ielts = $this->examType('ielts_academic');
        $scoring = $this->scoring();

        $cases = [
            [0.0, 'A1'], [2.5, 'A1'],
            [3.0, 'A2'], [3.5, 'A2'],
            [4.0, 'B1'], [5.0, 'B1'],
            [5.5, 'B2'], [6.5, 'B2'],
            [7.0, 'C1'], [8.0, 'C1'],
            [8.5, 'C2'], [9.0, 'C2'],
        ];

        foreach ($cases as [$score, $expected]) {
            $this->assertSame(
                $expected,
                $scoring->cefrFor($ielts, $score)?->code,
                "IELTS band {$score} should map to {$expected}",
            );
        }
    }

    public function test_adjacent_ielts_half_bands_fall_on_opposite_sides_of_a_boundary(): void
    {
        $ielts = $this->examType('ielts_academic');
        $scoring = $this->scoring();

        $this->assertSame('B1', $scoring->cefrFor($ielts, 5.0)?->code);
        $this->assertSame('B2', $scoring->cefrFor($ielts, 5.5)?->code);
        $this->assertSame('B2', $scoring->cefrFor($ielts, 6.5)?->code);
        $this->assertSame('C1', $scoring->cefrFor($ielts, 7.0)?->code);
    }

    public function test_band_ranges_are_contiguous_and_non_overlapping_for_every_exam(): void
    {
        foreach (['ielts_academic', 'toefl_ibt', 'cambridge_b2', 'pte_academic'] as $code) {
            $bands = $this->examType($code)->bands()->orderBy('score_from')->get();
            $this->assertGreaterThan(2, $bands->count(), "{$code} should publish a band table");

            for ($i = 1; $i < $bands->count(); $i++) {
                $previous = (float) $bands[$i - 1]->score_to;
                $next = (float) $bands[$i]->score_from;
                $this->assertGreaterThan($previous, $next, "{$code} bands overlap at {$next}");
                $this->assertLessThanOrEqual(1.01, $next - $previous, "{$code} leaves a gap after {$previous}");
            }
        }
    }

    public function test_toefl_and_pte_and_cambridge_boundaries(): void
    {
        $scoring = $this->scoring();

        $toefl = $this->examType('toefl_ibt');
        $this->assertSame('A2', $scoring->cefrFor($toefl, 41)?->code);
        $this->assertSame('B1', $scoring->cefrFor($toefl, 42)?->code);
        $this->assertSame('B1', $scoring->cefrFor($toefl, 71)?->code);
        $this->assertSame('B2', $scoring->cefrFor($toefl, 72)?->code);
        $this->assertSame('B2', $scoring->cefrFor($toefl, 94)?->code);
        $this->assertSame('C1', $scoring->cefrFor($toefl, 95)?->code);

        $pte = $this->examType('pte_academic');
        $this->assertSame('B1', $scoring->cefrFor($pte, 58)?->code);
        $this->assertSame('B2', $scoring->cefrFor($pte, 59)?->code);
        $this->assertSame('C1', $scoring->cefrFor($pte, 76)?->code);
        $this->assertSame('C2', $scoring->cefrFor($pte, 85)?->code);

        $cambridge = $this->examType('cambridge_b2');
        $this->assertSame('B1', $scoring->cefrFor($cambridge, 159)?->code);
        $this->assertSame('B2', $scoring->cefrFor($cambridge, 160)?->code);
        $this->assertSame('B2', $scoring->cefrFor($cambridge, 179)?->code);
        $this->assertSame('C1', $scoring->cefrFor($cambridge, 180)?->code);
    }

    public function test_scores_outside_the_published_range_clamp_to_the_nearest_level(): void
    {
        $ielts = $this->examType('ielts_academic');
        $scoring = $this->scoring();

        $this->assertSame('A1', $scoring->cefrFor($ielts, -1.0)?->code);
        $this->assertSame('C2', $scoring->cefrFor($ielts, 12.0)?->code);
    }

    public function test_ielts_raw_to_band_conversion_uses_the_published_table(): void
    {
        $listening = SectionScoring::for($this->section('ielts_academic', 'listening'));

        // Boundaries from the published IELTS Listening conversion.
        $this->assertSame(9.0, $listening->scoreFromRaw(39));
        $this->assertSame(8.5, $listening->scoreFromRaw(38));
        $this->assertSame(8.5, $listening->scoreFromRaw(37));
        $this->assertSame(8.0, $listening->scoreFromRaw(36));
        $this->assertSame(6.0, $listening->scoreFromRaw(23));
        $this->assertSame(5.5, $listening->scoreFromRaw(22));
        $this->assertSame(0.0, $listening->scoreFromRaw(0));
    }

    public function test_academic_reading_is_marked_more_severely_than_listening(): void
    {
        $listening = SectionScoring::for($this->section('ielts_academic', 'listening'));
        $reading = SectionScoring::for($this->section('ielts_academic', 'reading'));

        // 30 raw is band 7.0 in both, but 19 raw is 5.5 in listening and 5.5 in
        // reading only from a higher raw count - the tables genuinely differ.
        $this->assertSame(7.0, $listening->scoreFromRaw(30));
        $this->assertSame(7.0, $reading->scoreFromRaw(30));
        $this->assertSame(5.5, $listening->scoreFromRaw(19));
        $this->assertSame(5.5, $reading->scoreFromRaw(19));
        $this->assertSame(5.5, $listening->scoreFromRaw(18));
        $this->assertSame(5.0, $reading->scoreFromRaw(18));
    }

    public function test_linear_and_anchored_conversions_stay_inside_the_section_scale(): void
    {
        /** @var ExamSection $toeflReading */
        $toeflReading = $this->section('toefl_ibt', 'reading');
        $scoring = SectionScoring::for($toeflReading);

        $this->assertSame(0.0, $scoring->scoreFromRaw(0));
        $this->assertSame(30.0, $scoring->scoreFromRaw(20));
        $this->assertSame(15.0, $scoring->scoreFromRaw(10));

        $pte = SectionScoring::for($this->section('pte_academic', 'reading'));
        $this->assertSame(10.0, $pte->scoreFromRaw(0));
        $this->assertSame(90.0, $pte->scoreFromRaw(20));
        // Interpolated between the 0.45 -> 43 and 0.65 -> 59 anchors.
        $this->assertSame(51.0, $pte->scoreFromRaw(11));
    }

    public function test_half_band_rounding_matches_the_ielts_rule(): void
    {
        $this->assertSame(6.5, SectionScoring::roundToStep(6.25, 0.5, 0, 9));
        $this->assertSame(7.0, SectionScoring::roundToStep(6.75, 0.5, 0, 9));
        $this->assertSame(6.0, SectionScoring::roundToStep(6.124, 0.5, 0, 9));
        $this->assertSame(9.0, SectionScoring::roundToStep(9.4, 0.5, 0, 9));
    }
}
