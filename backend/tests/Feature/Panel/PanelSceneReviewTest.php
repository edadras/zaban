<?php

namespace Tests\Feature\Panel;

use App\Models\Language;
use App\Models\Scene;
use App\Models\SceneBeat;
use App\Models\User;

/**
 * Listening to a scene before learners do.
 *
 * The page exists for one decision - is this the right audio for this line -
 * and the test pins that the decision is recorded, that it belongs to platform
 * administrators, and that the review screen is not a way to edit the script.
 */
class PanelSceneReviewTest extends PanelTestCase
{
    private User $platformAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        // The shared fixture has a school owner and a coach but nobody who runs
        // the platform, which is whose screen this is.
        $this->platformAdmin = User::factory()->create(['role' => 'admin']);
    }

    private function scene(): Scene
    {
        $scene = Scene::create([
            'slug' => 'panel-scene',
            'language_id' => Language::where('code', 'en')->value('id'),
            'title' => 'At the desk',
            'title_fa' => 'پشت باجه',
            'environment' => 'hotel',
            'status' => 'published',
            'cast' => [
                ['role' => 'clerk', 'name' => 'Grace', 'playable' => false],
                ['role' => 'guest', 'name' => 'You', 'playable' => true],
            ],
        ]);

        SceneBeat::create([
            'scene_id' => $scene->id,
            'position' => 1,
            'role' => 'clerk',
            'text' => 'Good afternoon.',
            'translation_fa' => 'عصر بخیر.',
            'audio_method' => 'silence_segmentation',
            'audio_confidence' => 0.82,
        ]);

        return $scene->load('beats');
    }

    public function test_the_scene_list_counts_what_still_needs_listening_to(): void
    {
        $scene = $this->scene();

        $this->actingAs($this->platformAdmin)
            ->get('/panel/scenes')
            ->assertOk()
            ->assertSee($scene->title_fa)
            ->assertSee('برش از ضبط درس');
    }

    public function test_a_line_can_be_marked_as_heard_and_correct(): void
    {
        $scene = $this->scene();
        $beat = $scene->beats->first();

        $this->actingAs($this->platformAdmin)
            ->post("/panel/scenes/{$scene->id}/beats/{$beat->id}/review", ['status' => 'approved'])
            ->assertRedirect();

        $this->assertSame('approved', $beat->fresh()->audio_review_status);
    }

    public function test_rejecting_the_audio_keeps_the_recording(): void
    {
        $scene = $this->scene();
        $beat = $scene->beats->first();

        $this->actingAs($this->platformAdmin)
            ->post("/panel/scenes/{$scene->id}/beats/{$beat->id}/review", ['status' => 'rejected']);

        $fresh = $beat->fresh();
        $this->assertSame('rejected', $fresh->audio_review_status);
        // Re-cutting is the usual fix, and deleting first would make it harder.
        $this->assertSame('silence_segmentation', $fresh->audio_method);
    }

    public function test_a_coach_has_no_business_here(): void
    {
        $this->scene();

        $this->actingAs($this->coach)->get('/panel/scenes')->assertForbidden();
    }

    public function test_a_line_from_another_scene_cannot_be_reviewed_through_this_one(): void
    {
        $scene = $this->scene();

        $other = Scene::create([
            'slug' => 'other-scene',
            'language_id' => $scene->language_id,
            'title' => 'Elsewhere',
            'environment' => 'cafe',
            'status' => 'published',
            'cast' => $scene->cast,
        ]);
        $stray = SceneBeat::create([
            'scene_id' => $other->id, 'position' => 1, 'role' => 'clerk', 'text' => 'Hello.',
        ]);

        $this->actingAs($this->platformAdmin)
            ->post("/panel/scenes/{$scene->id}/beats/{$stray->id}/review", ['status' => 'approved'])
            ->assertNotFound();
    }

    public function test_the_review_page_offers_no_way_to_change_the_words(): void
    {
        $scene = $this->scene();

        $html = $this->actingAs($this->platformAdmin)->get("/panel/scenes/{$scene->id}")->assertOk()->content();

        // The scripts are reviewed in the repository, in a diff, with the rest
        // of the course - not typed into a box by whoever is logged in.
        $this->assertStringNotContainsString('name="text"', $html);
        $this->assertStringContainsString('name="status"', $html);
    }

    public function test_an_unknown_user_is_sent_to_the_login_page(): void
    {
        $this->get('/panel/scenes')->assertRedirect(route('panel.login'));
    }
}
