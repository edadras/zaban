<?php

namespace Tests\Feature\Scene;

use App\Models\Language;
use App\Models\Scene;
use App\Models\SceneBeat;
use App\Models\SceneSession;
use App\Models\User;
use App\Models\XpTransaction;
use App\Services\Scene\SceneDirector;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Playing an acted scene.
 *
 * The promises pinned here are the ones a learner would notice if they broke:
 * the line they are supposed to produce is not sitting in the page waiting to
 * be read off, a near-enough answer counts, a wrong one costs a try rather than
 * the scene, and after three tries the scene hands over the line and carries on.
 */
class SceneRunTest extends TestCase
{
    use RefreshDatabase;

    private User $learner;

    private Scene $scene;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);

        $this->learner = User::factory()->create();
        $this->scene = $this->scene();
    }

    private function scene(): Scene
    {
        $scene = Scene::create([
            'slug' => 'test-desk',
            'language_id' => Language::where('code', 'en')->value('id'),
            'title' => 'At the desk',
            'title_fa' => 'پشت باجه',
            'environment' => 'hotel',
            'light' => 1.0,
            'status' => 'published',
            'published_at' => now(),
            'cast' => [
                ['role' => 'clerk', 'name' => 'Grace', 'playable' => false, 'x' => -0.8, 'z' => 0],
                ['role' => 'guest', 'name' => 'You', 'playable' => true, 'x' => 0.8, 'z' => 0],
            ],
            'vocabulary' => [['word' => 'booking', 'fa' => 'رزرو']],
        ]);

        $lines = [
            ['clerk', 'Good afternoon. Do you have a booking?', 'watch', null, null],
            ['guest', 'Yes, I have a booking for three nights.', 'speak',
                ['Yes, I have a reservation for three nights.'], null],
            ['clerk', 'Lovely. What name is it under?', 'watch', null, null],
            ['guest', 'It is under Karimi.', 'choose', null, [
                ['text' => 'It is under Karimi.', 'correct' => true],
                ['text' => 'My name has the booking.', 'correct' => false],
            ]],
        ];

        foreach ($lines as $position => [$role, $text, $interaction, $accept, $choices]) {
            SceneBeat::create([
                'scene_id' => $scene->id,
                'position' => $position + 1,
                'role' => $role,
                'text' => $text,
                'interaction' => $interaction,
                'prompt' => $interaction === 'watch' ? null : 'Say it.',
                'accept' => $accept,
                'choices' => $choices,
                'hint' => 'Start with Yes.',
            ]);
        }

        return $scene->load('beats');
    }

    private function start(string $mode = 'guided'): array
    {
        return $this->actingAs($this->learner)
            ->postJson('/api/v1/conversation/scenes/start', [
                'scene_id' => $this->scene->id,
                'role' => 'guest',
                'mode' => $mode,
            ])->assertCreated()->json('data');
    }

    private function beat(int $position): SceneBeat
    {
        return $this->scene->beats->firstWhere('position', $position);
    }

    private function answer(int $sessionId, int $beatId, array $payload): array
    {
        return $this->actingAs($this->learner)
            ->postJson("/api/v1/conversation/scene-sessions/{$sessionId}/beats/{$beatId}/answer", $payload)
            ->assertOk()->json('data.verdict');
    }

    public function test_a_published_scene_is_listed_with_its_roles(): void
    {
        $body = $this->actingAs($this->learner)
            ->getJson('/api/v1/conversation/scenes')
            ->assertOk()->json('data');

        $this->assertCount(1, $body);
        $this->assertSame('test-desk', $body[0]['slug']);
        $this->assertSame(4, $body[0]['line_count']);
        $this->assertEqualsCanonicalizing(['clerk', 'guest'], array_column($body[0]['roles'], 'role'));
    }

    public function test_a_draft_scene_is_not_playable(): void
    {
        $this->scene->update(['status' => 'draft']);

        $this->actingAs($this->learner)
            ->postJson('/api/v1/conversation/scenes/start', ['scene_id' => $this->scene->id])
            ->assertNotFound();
    }

    public function test_the_learners_own_lines_are_withheld_until_they_are_earned(): void
    {
        $data = $this->start();

        $byRole = collect($data['beats'])->groupBy('role');

        // The other person's lines are all there: they are about to be spoken.
        $this->assertNotContains(null, $byRole['clerk']->pluck('text')->all());

        // The learner's are not, or the practice is a reading exercise.
        $this->assertSame([null, null], $byRole['guest']->pluck('text')->all());

        // The prompt and the translation still are - that is how they know what to say.
        $this->assertSame('Say it.', $byRole['guest'][0]['prompt']);
    }

    public function test_a_choice_never_tells_the_client_which_option_is_right(): void
    {
        $data = $this->start();
        $choose = collect($data['beats'])->firstWhere('interaction', 'choose');

        foreach ($choose['choices'] as $choice) {
            $this->assertArrayNotHasKey('correct', $choice);
        }
    }

    public function test_a_close_enough_line_is_accepted_and_hands_the_line_over(): void
    {
        $session = $this->start();
        $beat = $this->beat(2);

        $verdict = $this->answer($session['session']['id'], $beat->id, [
            'text' => 'Yes I have a booking for three nights',
        ]);

        $this->assertTrue($verdict['accepted']);
        $this->assertSame(1, $verdict['tries']);
        $this->assertSame('correct_first_try', $verdict['feedback']);
        $this->assertSame($beat->text, $verdict['line']);
    }

    public function test_an_accepted_alternative_wording_counts(): void
    {
        $session = $this->start();

        $verdict = $this->answer($session['session']['id'], $this->beat(2)->id, [
            'text' => 'Yes, I have a reservation for three nights.',
        ]);

        $this->assertTrue($verdict['accepted']);
    }

    public function test_a_wrong_line_costs_a_try_and_names_the_missing_words(): void
    {
        $session = $this->start();

        $verdict = $this->answer($session['session']['id'], $this->beat(2)->id, [
            'text' => 'Yes I have',
        ]);

        $this->assertFalse($verdict['accepted']);
        $this->assertSame(2, $verdict['tries_left']);
        $this->assertNull($verdict['line'], 'A line not yet earned must not be handed over.');
        $this->assertContains('booking', $verdict['missing']);
    }

    public function test_the_third_failure_gives_the_line_rather_than_locking_the_scene(): void
    {
        $session = $this->start();
        $beat = $this->beat(2);

        $this->answer($session['session']['id'], $beat->id, ['text' => 'no']);
        $this->answer($session['session']['id'], $beat->id, ['text' => 'no']);
        $third = $this->answer($session['session']['id'], $beat->id, ['text' => 'no']);

        $this->assertFalse($third['accepted']);
        $this->assertTrue($third['revealed']);
        $this->assertSame($beat->text, $third['line']);
        $this->assertSame('revealed', $third['feedback']);

        // And the run has moved past it.
        $this->assertSame(
            $beat->position + 1,
            SceneSession::find($session['session']['id'])->position,
        );
    }

    public function test_the_wrong_option_is_not_accepted(): void
    {
        $session = $this->start();

        $wrong = $this->answer($session['session']['id'], $this->beat(4)->id, ['choice' => 1]);
        $this->assertFalse($wrong['accepted']);

        $right = $this->answer($session['session']['id'], $this->beat(4)->id, ['choice' => 0]);
        $this->assertTrue($right['accepted']);
    }

    public function test_roleplay_turns_every_line_of_the_role_into_a_spoken_one(): void
    {
        $data = $this->start('roleplay');

        $guest = collect($data['beats'])->where('role', 'guest');
        $this->assertEqualsCanonicalizing(['speak', 'choose'], $guest->pluck('interaction')->unique()->values()->all());

        // The other role is still only watched: nobody is asked to answer for
        // the person they are talking to.
        $this->assertSame(['watch'], collect($data['beats'])->where('role', 'clerk')
            ->pluck('interaction')->unique()->values()->all());
    }

    public function test_watching_asks_nothing(): void
    {
        $data = $this->start('watch');

        $this->assertSame(
            ['watch'],
            collect($data['beats'])->pluck('interaction')->unique()->values()->all(),
        );
        // And every line is visible, because none of them is the learner's to produce.
        $this->assertNotContains(null, collect($data['beats'])->pluck('text')->all());
    }

    public function test_finishing_scores_the_run_and_lists_what_to_practise(): void
    {
        $session = $this->start();
        $id = $session['session']['id'];

        $this->answer($id, $this->beat(2)->id, ['text' => 'Yes, I have a booking for three nights.']);
        $this->answer($id, $this->beat(4)->id, ['choice' => 1]);

        $body = $this->actingAs($this->learner)
            ->postJson("/api/v1/conversation/scene-sessions/{$id}/finish")
            ->assertOk()->json('data');

        $this->assertSame('completed', $body['status']);
        $this->assertGreaterThan(0, $body['score']);
        $this->assertSame(2, $body['summary']['lines_asked']);
        $this->assertSame(1, $body['summary']['lines_cleared']);
        $this->assertSame('It is under Karimi.', $body['summary']['to_practise'][0]['line']);
    }

    public function test_a_finished_run_refuses_further_answers(): void
    {
        $session = $this->start();
        $id = $session['session']['id'];

        $this->actingAs($this->learner)->postJson("/api/v1/conversation/scene-sessions/{$id}/finish")->assertOk();

        $this->actingAs($this->learner)
            ->postJson("/api/v1/conversation/scene-sessions/{$id}/beats/{$this->beat(2)->id}/answer", ['text' => 'x'])
            ->assertStatus(409);
    }

    public function test_a_run_belonging_to_someone_else_is_not_found(): void
    {
        $session = $this->start();
        $other = User::factory()->create();

        $this->actingAs($other)
            ->getJson("/api/v1/conversation/scene-sessions/{$session['session']['id']}")
            ->assertNotFound();
    }

    public function test_a_beat_from_another_scene_is_rejected(): void
    {
        $session = $this->start();

        $elsewhere = Scene::create([
            'slug' => 'other', 'language_id' => $this->scene->language_id, 'title' => 'Other',
            'environment' => 'cafe', 'status' => 'published', 'cast' => $this->scene->cast,
        ]);
        $stray = SceneBeat::create([
            'scene_id' => $elsewhere->id, 'position' => 1, 'role' => 'guest',
            'text' => 'Hello.', 'interaction' => 'speak', 'prompt' => 'Say hello.',
        ]);

        $this->actingAs($this->learner)
            ->postJson("/api/v1/conversation/scene-sessions/{$session['session']['id']}/beats/{$stray->id}/answer", [
                'text' => 'Hello.',
            ])->assertStatus(422);
    }

    public function test_reopening_a_scene_returns_to_the_run_already_in_progress(): void
    {
        $first = $this->start();
        $this->answer($first['session']['id'], $this->beat(2)->id, [
            'text' => 'Yes, I have a booking for three nights.',
        ]);

        $again = $this->start();

        $this->assertSame($first['session']['id'], $again['session']['id']);
        $this->assertSame(1, $again['session']['cleared']);

        // The line they earned is theirs to keep seeing.
        $earned = collect($again['beats'])->firstWhere('position', 2);
        $this->assertSame('Yes, I have a booking for three nights.', $earned['text']);
    }

    public function test_completing_a_scene_awards_progress_once(): void
    {
        $session = $this->start();
        $id = $session['session']['id'];
        $this->answer($id, $this->beat(2)->id, ['text' => 'Yes, I have a booking for three nights.']);

        app(SceneDirector::class)->finish(SceneSession::find($id));
        app(SceneDirector::class)->finish(SceneSession::find($id));

        $this->assertSame(1, XpTransaction::where('user_id', $this->learner->id)
            ->where('reason', 'scene_complete')->count());
    }
}
