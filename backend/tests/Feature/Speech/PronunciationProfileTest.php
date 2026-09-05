<?php

namespace Tests\Feature\Speech;

use App\Models\Phoneme;
use App\Models\PronunciationError;
use App\Services\Speech\PronunciationProfileService;
use Database\Seeders\PhonemeSeeder;

/**
 * The profile is the thing that outlives the audio, so its arithmetic has to be
 * exactly right: a wrong denominator here means a learner is drilled on a sound
 * they can already say.
 */
class PronunciationProfileTest extends SpeechTestCase
{
    private PronunciationProfileService $profile;

    private Phoneme $theta;

    private Phoneme $ess;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PhonemeSeeder::class);
        $this->profile = app(PronunciationProfileService::class);
        $this->theta = Phoneme::where('ipa', 'θ')->firstOrFail();
        $this->ess = Phoneme::where('ipa', 's')->firstOrFail();
    }

    /** @return array<int,array<string,mixed>> */
    private function observations(int $opportunities, int $errors, ?int $substitute = null, string $word = 'think'): array
    {
        $out = [];
        for ($i = 0; $i < $opportunities; $i++) {
            $isError = $i < $errors;
            $out[] = [
                'phoneme_id' => $this->theta->id,
                'substituted_phoneme_id' => $isError ? $substitute : null,
                'is_error' => $isError,
                'word' => $isError ? $word : null,
            ];
        }

        return $out;
    }

    public function test_first_attempt_sets_both_rates_to_the_observed_rate(): void
    {
        $user = $this->learner();

        $this->profile->record($user->id, $this->observations(10, 4, $this->ess->id));

        $row = $this->canonicalRow($user->id);
        $this->assertSame(10, (int) $row->attempt_count);
        $this->assertSame(4, (int) $row->occurrence_count);
        $this->assertEqualsWithDelta(0.400, $row->error_rate, 0.0005);
        $this->assertEqualsWithDelta(0.400, $row->recent_error_rate, 0.0005);
        $this->assertSame(['think'], $row->example_words);
        $this->assertNull($row->resolved_at);
    }

    public function test_lifetime_rate_pools_attempts_while_the_recent_rate_follows_the_newest_one(): void
    {
        $user = $this->learner();

        $this->profile->record($user->id, $this->observations(10, 4, $this->ess->id));
        $this->profile->record($user->id, $this->observations(10, 1, $this->ess->id));

        $row = $this->canonicalRow($user->id);
        $this->assertSame(20, (int) $row->attempt_count);
        $this->assertSame(5, (int) $row->occurrence_count);
        // Lifetime: 5 errors in 20 opportunities.
        $this->assertEqualsWithDelta(0.250, $row->error_rate, 0.0005);
        // Windowed: 0.65 * 0.400 + 0.35 * 0.100.
        $this->assertEqualsWithDelta(0.295, $row->recent_error_rate, 0.0005);
    }

    public function test_a_learner_who_improves_stops_being_flagged_as_a_problem(): void
    {
        $user = $this->learner();

        $this->profile->record($user->id, $this->observations(10, 6, $this->ess->id));
        $this->assertSame(
            [$this->theta->id],
            $this->profile->problemPhonemes($user->id)->pluck('phoneme_id')->all(),
        );

        // Three clean attempts in a row.
        for ($i = 0; $i < 3; $i++) {
            $this->profile->record($user->id, $this->observations(10, 0));
        }

        $row = $this->canonicalRow($user->id);
        // 0.6 -> 0.39 -> 0.254 -> 0.165 through the window, while the lifetime
        // rate is still 0.15 because those six errors really happened.
        $this->assertEqualsWithDelta(0.165, $row->recent_error_rate, 0.0015);
        $this->assertEqualsWithDelta(0.150, $row->error_rate, 0.0005);
        $this->assertTrue($row->recent_error_rate > 0 && $row->recent_error_rate < PronunciationProfileService::PROBLEM_THRESHOLD);
        $this->assertEmpty($this->profile->problemPhonemes($user->id));
    }

    public function test_a_clean_run_below_the_resolved_threshold_marks_the_phoneme_resolved(): void
    {
        $user = $this->learner();

        $this->profile->record($user->id, $this->observations(10, 2, $this->ess->id));
        for ($i = 0; $i < 6; $i++) {
            $this->profile->record($user->id, $this->observations(10, 0));
        }

        $row = $this->canonicalRow($user->id);
        $this->assertLessThanOrEqual(PronunciationProfileService::RESOLVED_THRESHOLD, (float) $row->recent_error_rate);
        $this->assertNotNull($row->resolved_at);

        // ...and one fresh error reopens it.
        $this->profile->record($user->id, $this->observations(4, 1, $this->ess->id));
        $this->assertNull($this->canonicalRow($user->id)->resolved_at);
    }

    public function test_a_rate_with_too_few_opportunities_is_not_acted_on(): void
    {
        $user = $this->learner();

        $this->profile->record($user->id, $this->observations(4, 4, $this->ess->id));

        $row = $this->canonicalRow($user->id);
        $this->assertEqualsWithDelta(1.0, $row->error_rate, 0.0005);
        $this->assertLessThan(PronunciationProfileService::MIN_OPPORTUNITIES, (int) $row->attempt_count);
        $this->assertEmpty($this->profile->problemPhonemes($user->id));
    }

    public function test_substitutions_are_tracked_against_the_same_denominator(): void
    {
        $user = $this->learner();

        $this->profile->record($user->id, $this->observations(10, 3, $this->ess->id));

        $sub = PronunciationError::where('user_id', $user->id)
            ->where('phoneme_id', $this->theta->id)
            ->where('substituted_phoneme_id', $this->ess->id)
            ->firstOrFail();

        $this->assertSame(3, (int) $sub->occurrence_count);
        $this->assertSame(10, (int) $sub->attempt_count);
        $this->assertEqualsWithDelta(0.300, $sub->error_rate, 0.0005);

        // The canonical row must not double count the substitution row.
        $this->assertSame(3, (int) $this->canonicalRow($user->id)->occurrence_count);
    }

    public function test_drill_targets_carry_the_teaching_material_for_the_problem_sound(): void
    {
        $user = $this->learner();
        $this->profile->record($user->id, $this->observations(12, 7, $this->ess->id));

        $targets = $this->profile->drillTargets($user->id);

        $this->assertCount(1, $targets);
        $target = $targets[0];
        $this->assertSame('θ', $target['ipa']);
        $this->assertSame('TH', $target['arpabet']);
        $this->assertNotEmpty($target['articulation_hint']);
        $this->assertSame(['think'], $target['example_words']);
        $this->assertSame('s', $target['confused_with'][0]['ipa']);

        $words = collect($target['minimal_pairs'])
            ->flatMap(fn ($p) => [$p['a']['text'], $p['b']['text']])
            ->all();
        $this->assertContains('think', $words);
        $this->assertContains('sink', $words);
    }

    public function test_the_profile_endpoint_reports_rates_and_drill_targets(): void
    {
        $user = $this->learner();
        $this->profile->record($user->id, $this->observations(10, 5, $this->ess->id));

        $response = $this->actingAs($user)->getJson('/api/v1/speech/profile');

        $response->assertOk()
            ->assertJsonPath('data.phonemes.0.ipa', 'θ')
            ->assertJsonPath('data.phonemes.0.attempt_count', 10)
            ->assertJsonPath('data.phonemes.0.occurrence_count', 5)
            ->assertJsonPath('data.phonemes.0.is_problem', true)
            ->assertJsonPath('data.drill_targets.0.ipa', 'θ')
            ->assertJsonPath('data.thresholds.min_opportunities', PronunciationProfileService::MIN_OPPORTUNITIES);
    }

    public function test_the_drills_endpoint_returns_only_the_actionable_targets(): void
    {
        $user = $this->learner();
        // Enough opportunities to act on, and a rate above the threshold.
        $this->profile->record($user->id, $this->observations(10, 5, $this->ess->id));

        $this->actingAs($user)->getJson('/api/v1/speech/profile/drills?limit=3')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.ipa', 'θ')
            ->assertJsonPath('data.0.improving', false);
    }

    private function canonicalRow(int $userId): PronunciationError
    {
        return PronunciationError::where('user_id', $userId)
            ->where('phoneme_id', $this->theta->id)
            ->whereNull('substituted_phoneme_id')
            ->firstOrFail();
    }
}
