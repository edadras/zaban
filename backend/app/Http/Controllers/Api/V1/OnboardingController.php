<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\UserResource;
use App\Models\Language;
use App\Models\UserProfile;
use App\Models\UserSetting;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The four questions asked once, before anything else.
 *
 * The client has had this screen since the app was written and the routes did
 * not exist, so the first thing a new account saw after registering was an
 * error. Everything it collects already had a column - `users.locale`,
 * `user_profiles.learning_objective`, `user_settings.daily_target_minutes` -
 * so this is a shape over what was already there, not a new store.
 *
 * The options come from the server rather than being hard-coded in the app for
 * one reason that matters: the interface languages are the ones the app has
 * actually been translated into, and a list baked into the client goes stale
 * the moment a translation lands or is withdrawn.
 */
class OnboardingController extends ApiController
{
    /**
     * What the app has been translated into.
     *
     * `languages.is_interface` marks a language the product may be *shown* in,
     * which is a different question from `is_learnable` - a language it can
     * teach. Persian is one and not the other.
     */
    private const TRANSLATED = ['en', 'fa'];

    /** The goals `ProfileController` accepts, with wording a learner reads. */
    private const GOALS = [
        'general_english' => ['Everyday English', 'Reading, listening and getting by'],
        'conversation' => ['Talking to people', 'Fluency and confidence in speech'],
        'travel' => ['Travel', 'Airports, hotels, directions, small talk'],
        'work' => ['Work', 'Email, meetings and the language of your job'],
        'business' => ['Business', 'Negotiation, presentation and formal writing'],
        'academic' => ['Study', 'Academic reading and writing'],
        'ielts' => ['IELTS', 'Preparing for the exam'],
        'toefl' => ['TOEFL', 'Preparing for the exam'],
        'cambridge' => ['Cambridge exams', 'FCE, CAE and CPE'],
    ];

    public function options()
    {
        $languages = Language::orderBy('name')->get();

        return $this->ok([
            'interface_languages' => $languages
                ->where('is_interface', true)
                // Offered only where there is a translation to offer. A picker
                // that lists six languages and then shows English in all six is
                // worse than a picker that lists two.
                ->whereIn('code', self::TRANSLATED)
                ->map($this->asOption(...))->values(),
            'target_languages' => $languages
                ->where('is_learnable', true)
                ->map($this->asOption(...))->values(),
            'goals' => collect(self::GOALS)->map(fn (array $g, string $code) => [
                'code' => $code,
                'label' => $g[0],
                'description' => $g[1],
            ])->values(),
            'daily_targets' => [5, 10, 15, 20, 30, 45],
            'default_daily_target' => 15,
        ]);
    }

    /**
     * Record the answers and hand back the user the app should now render.
     *
     * Returns the whole user rather than an acknowledgement: the client's next
     * screen is driven by the learner profile, and a second round trip to fetch
     * what this call just wrote is a race with itself.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'interface_language' => ['required', Rule::in(self::TRANSLATED)],
            'target_language' => [
                'required',
                Rule::exists('languages', 'code')->where('is_learnable', true),
            ],
            'daily_target_minutes' => ['required', 'integer', 'min:5', 'max:120'],
            'goal' => ['nullable', Rule::in(array_keys(self::GOALS))],
        ]);

        $user = $request->user();
        $user->update(['locale' => $data['interface_language']]);

        $profile = UserProfile::firstOrCreate(['user_id' => $user->id]);
        $profile->fill([
            'target_language_id' => Language::where('code', $data['target_language'])->value('id'),
            'learning_objective' => $data['goal'] ?? $profile->learning_objective,
        ]);
        // The interface language is the best evidence of a first language that
        // anyone has volunteered, and it is only used as a default: the profile
        // screen can correct it, and nothing infers anything else from it.
        if ($profile->native_language_id === null) {
            $profile->native_language_id = Language::where('code', $data['interface_language'])->value('id');
        }
        $profile->save();

        $settings = UserSetting::firstOrCreate(['user_id' => $user->id]);
        $settings->update([
            'daily_target_minutes' => $data['daily_target_minutes'],
            'weekly_goal_minutes' => $data['daily_target_minutes'] * 7,
        ]);

        return $this->ok(new UserResource(
            $user->fresh()->load(['profile', 'settings', 'learnerProfile.cefrLevel']),
        ));
    }

    /** @return array<string, mixed> */
    private function asOption(Language $language): array
    {
        return [
            'code' => $language->code,
            'name' => $language->name,
            'native_name' => $language->native_name,
            'direction' => $language->direction,
        ];
    }
}
