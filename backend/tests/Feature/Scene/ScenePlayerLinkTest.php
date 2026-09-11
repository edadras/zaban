<?php

namespace Tests\Feature\Scene;

use App\Models\Language;
use App\Models\Scene;
use App\Models\SceneBeat;
use App\Models\SceneSession;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * The link that opens the player.
 *
 * The page runs in a web view inside the app and in a browser tab, and neither
 * can be handed the learner's bearer token. It is reached by a signed link
 * instead, and everything below is about that trade being made deliberately:
 * the signature is required on every call, it expires, and it authorises one
 * run of one scene and nothing else.
 */
class ScenePlayerLinkTest extends TestCase
{
    use RefreshDatabase;

    private User $learner;

    private Scene $scene;

    private SceneSession $session;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        $this->withoutVite();

        $this->learner = User::factory()->create();

        $this->scene = Scene::create([
            'slug' => 'link-test',
            'language_id' => Language::where('code', 'en')->value('id'),
            'title' => 'At the desk',
            'environment' => 'hotel',
            'status' => 'published',
            'published_at' => now(),
            'cast' => [
                ['role' => 'clerk', 'name' => 'Grace', 'playable' => false],
                ['role' => 'guest', 'name' => 'You', 'playable' => true],
            ],
        ]);

        SceneBeat::create([
            'scene_id' => $this->scene->id, 'position' => 1, 'role' => 'clerk',
            'text' => 'Good afternoon.', 'interaction' => 'watch',
        ]);
        SceneBeat::create([
            'scene_id' => $this->scene->id, 'position' => 2, 'role' => 'guest',
            'text' => 'I have a booking.', 'interaction' => 'speak', 'prompt' => 'Say it.',
        ]);

        $this->session = SceneSession::create([
            'user_id' => $this->learner->id,
            'scene_id' => $this->scene->id,
            'role' => 'guest',
            'mode' => 'guided',
            'status' => 'active',
        ]);
    }

    private function signed(string $route): string
    {
        return URL::temporarySignedRoute($route, now()->addHour(), ['session' => $this->session->id]);
    }

    public function test_starting_a_run_hands_back_a_link_to_the_player(): void
    {
        $body = $this->actingAs($this->learner)
            ->postJson('/api/v1/conversation/scenes/start', [
                'scene_id' => $this->scene->id,
                'role' => 'guest',
            ])->assertCreated()->json('data');

        $this->assertNotEmpty($body['player_url']);
        $this->assertStringContainsString('/scene/', $body['player_url']);
        $this->assertStringContainsString('signature=', $body['player_url']);
        $this->assertGreaterThan(0, $body['expires_in']);
    }

    public function test_the_player_page_loads_from_a_signed_link(): void
    {
        $this->get($this->signed('scene.play'))
            ->assertOk()
            ->assertSee('window.__SCENE__', false);
    }

    public function test_the_player_page_refuses_an_unsigned_link(): void
    {
        $this->get("/scene/{$this->session->id}/play")->assertForbidden();
    }

    public function test_a_tampered_link_is_refused(): void
    {
        $link = $this->signed('scene.play');

        $this->get(str_replace('signature=', 'signature=0', $link))->assertForbidden();
    }

    public function test_the_page_withholds_the_line_the_learner_owes(): void
    {
        $html = $this->get($this->signed('scene.play'))->assertOk()->content();

        $this->assertStringContainsString('Good afternoon.', $html);
        // Their own line is not sitting in the page waiting to be read off.
        $this->assertStringNotContainsString('I have a booking.', $html);
    }

    public function test_the_modelled_kit_is_offered_only_when_it_is_actually_there(): void
    {
        $html = $this->get($this->signed('scene.play'))->assertOk()->content();
        $bootstrap = $this->bootstrapOf($html);

        foreach (config('scene.kit') as $what => $path) {
            $offered = $bootstrap['kit'][$what] ?? null;
            $present = file_exists(public_path(ltrim((string) $path, '/')));

            if ($present) {
                $this->assertNotNull($offered, "the {$what} kit is on disk and should be offered");
                $this->assertStringContainsString((string) $path, $offered);
            } else {
                // A player handed a URL for a file that is not there would draw
                // nothing while it waited for a 404.
                $this->assertNull($offered, "the {$what} kit is not on disk and must not be offered");
            }
        }
    }

    public function test_a_missing_kit_does_not_stop_a_scene_opening(): void
    {
        config(['scene.kit' => ['cast' => '/scene-kit/nothing-here.glb', 'rooms' => null]]);

        $bootstrap = $this->bootstrapOf(
            $this->get($this->signed('scene.play'))->assertOk()->content(),
        );

        $this->assertNull($bootstrap['kit']['cast']);
        // And the scene itself is still fully described, so the player can draw
        // it with the figures and the room it builds itself.
        $this->assertSame('link-test', $bootstrap['scene']['slug']);
        $this->assertCount(2, $bootstrap['scene']['cast']);
    }

    /** The bootstrap object the page hands the player. */
    private function bootstrapOf(string $html): array
    {
        $this->assertMatchesRegularExpression('/window\.__SCENE__ = (.+);<\/script>/', $html);
        preg_match('/window\.__SCENE__ = (.+);<\/script>/', $html, $matches);

        return json_decode(html_entity_decode($matches[1]), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_the_page_carries_no_bearer_token(): void
    {
        $html = $this->get($this->signed('scene.play'))->assertOk()->content();

        $this->assertStringNotContainsString('Bearer', $html);
        $this->assertStringNotContainsString('api_token', $html);
    }

    public function test_answering_through_the_signed_endpoint_is_graded_by_the_server(): void
    {
        $beat = $this->scene->beats()->where('position', 2)->first();

        $verdict = $this->postJson($this->signed('scene.answer'), [
            'beat_id' => $beat->id,
            'text' => 'I have a booking',
        ])->assertOk()->json('data.verdict');

        $this->assertTrue($verdict['accepted']);
    }

    public function test_answering_without_a_signature_is_refused(): void
    {
        $beat = $this->scene->beats()->where('position', 2)->first();

        $this->postJson("/scene/{$this->session->id}/answer", [
            'beat_id' => $beat->id,
            'text' => 'I have a booking',
        ])->assertForbidden();
    }

    public function test_an_expired_link_stops_working(): void
    {
        $link = URL::temporarySignedRoute(
            'scene.play', now()->addMinutes(5), ['session' => $this->session->id],
        );

        $this->travel(10)->minutes();

        $this->get($link)->assertForbidden();
    }

    public function test_the_link_opens_one_run_and_not_another(): void
    {
        $other = SceneSession::create([
            'user_id' => User::factory()->create()->id,
            'scene_id' => $this->scene->id,
            'role' => 'guest',
            'status' => 'active',
        ]);

        // A signature is bound to the session it was minted for, so swapping the
        // id in the path invalidates it rather than opening someone else's run.
        $link = str_replace(
            "/scene/{$this->session->id}/",
            "/scene/{$other->id}/",
            $this->signed('scene.play'),
        );

        $this->get($link)->assertForbidden();
    }

    public function test_finishing_through_the_link_closes_the_run(): void
    {
        $this->postJson($this->signed('scene.finish'))->assertOk();

        $this->assertSame('completed', $this->session->fresh()->status);
    }
}
