<?php

namespace Tests\Feature\Onboarding;

use App\Models\Language;
use App\Models\User;
use App\Models\UserProfile;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The first screen after registering.
 *
 * It had been in the app since the app was written and the two routes it calls
 * did not exist, so a new account's first experience of the product was an
 * error page. These tests are mostly about the two things that make the screen
 * worth having: the options being real, and the answers actually landing
 * somewhere the rest of the app reads.
 */
class OnboardingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
    }

    public function test_the_interface_languages_offered_are_the_ones_that_exist(): void
    {
        $data = $this->actingAs(User::factory()->create())
            ->getJson('/api/v1/onboarding/options')
            ->assertOk()
            ->json('data');

        $codes = collect($data['interface_languages'])->pluck('code');

        // English and Persian are translated; the other four interface
        // languages in the table are not, and offering them would promise a
        // translation that does not exist.
        $this->assertEqualsCanonicalizing(['en', 'fa'], $codes->all());
        $this->assertSame(
            'rtl',
            collect($data['interface_languages'])->firstWhere('code', 'fa')['direction'],
        );
    }

    public function test_only_a_language_the_platform_teaches_can_be_the_target(): void
    {
        $data = $this->actingAs(User::factory()->create())
            ->getJson('/api/v1/onboarding/options')
            ->assertOk()
            ->json('data');

        $this->assertSame(['en'], collect($data['target_languages'])->pluck('code')->all());
        $this->assertNotEmpty($data['goals']);
    }

    public function test_the_answers_land_on_the_account_the_profile_and_the_settings(): void
    {
        $user = User::factory()->create(['locale' => 'en']);

        $body = $this->actingAs($user)
            ->postJson('/api/v1/onboarding', [
                'interface_language' => 'fa',
                'target_language' => 'en',
                'daily_target_minutes' => 20,
                'goal' => 'ielts',
            ])
            ->assertOk()
            ->json('data');

        $this->assertSame('fa', $body['locale']);
        $this->assertSame('fa', $user->fresh()->locale);

        $profile = UserProfile::where('user_id', $user->id)->firstOrFail();
        $this->assertSame('ielts', $profile->learning_objective);
        $this->assertSame(
            Language::where('code', 'en')->value('id'),
            $profile->target_language_id,
        );
        // The interface language is the only evidence of a first language
        // anyone has volunteered, and it is used as a default rather than
        // inferred from anything else.
        $this->assertSame(
            Language::where('code', 'fa')->value('id'),
            $profile->native_language_id,
        );

        $this->assertSame(20, (int) DB::table('user_settings')
            ->where('user_id', $user->id)->value('daily_target_minutes'));
    }

    public function test_an_untranslated_interface_language_is_refused(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson('/api/v1/onboarding', [
                'interface_language' => 'ru',
                'target_language' => 'en',
                'daily_target_minutes' => 15,
            ])
            ->assertStatus(422);
    }

    public function test_a_language_the_platform_does_not_teach_cannot_be_the_target(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson('/api/v1/onboarding', [
                'interface_language' => 'en',
                'target_language' => 'fa',
                'daily_target_minutes' => 15,
            ])
            ->assertStatus(422);
    }

    public function test_onboarding_needs_an_account(): void
    {
        $this->getJson('/api/v1/onboarding/options')->assertStatus(401);
    }

    /**
     * Answering twice must correct the account rather than create a second
     * profile - a learner who changes their mind on the last screen goes back.
     */
    public function test_answering_again_corrects_rather_than_duplicates(): void
    {
        $user = User::factory()->create();

        foreach (['work', 'travel'] as $goal) {
            $this->actingAs($user)->postJson('/api/v1/onboarding', [
                'interface_language' => 'en',
                'target_language' => 'en',
                'daily_target_minutes' => 30,
                'goal' => $goal,
            ])->assertOk();
        }

        $this->assertSame(1, UserProfile::where('user_id', $user->id)->count());
        $this->assertSame(
            'travel',
            UserProfile::where('user_id', $user->id)->value('learning_objective'),
        );
    }
}
