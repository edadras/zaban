<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\UserResource;
use App\Models\Language;
use App\Models\PrivacyRequest;
use App\Services\Privacy\PrivacyRequestService;
use App\Models\UserProfile;
use App\Models\UserSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class ProfileController extends ApiController
{
    public function show(Request $request)
    {
        return $this->ok(new UserResource(
            $request->user()->load('profile', 'settings', 'learnerProfile.cefrLevel')
        ));
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'timezone' => ['sometimes', 'string', 'timezone'],
            'locale' => ['sometimes', 'string', 'max:12'],
            'country_code' => ['sometimes', 'nullable', 'string', 'size:2'],
            'date_of_birth' => ['sometimes', 'nullable', 'date', 'before:today'],
            'native_language' => ['sometimes', 'nullable', 'string', Rule::exists('languages', 'code')],
            'target_language' => ['sometimes', 'string', Rule::exists('languages', 'code')->where('is_learnable', true)],
            'learning_objective' => ['sometimes', 'nullable', Rule::in([
                'general_english', 'conversation', 'travel', 'work', 'academic',
                'ielts', 'toefl', 'cambridge', 'business',
            ])],
            // Personalisation inputs are deliberately limited to safe, declared
            // interests - never inferred or sensitive categories (spec 53).
            'profession' => ['sometimes', 'nullable', 'string', 'max:120'],
            'interests' => ['sometimes', 'nullable', 'array', 'max:12'],
            'interests.*' => ['string', 'max:40'],
            'favourite_topics' => ['sometimes', 'nullable', 'array', 'max:12'],
            'favourite_topics.*' => ['string', 'max:40'],
        ]);

        $user = $request->user();
        $user->fill(array_intersect_key($data, array_flip(['name', 'timezone', 'locale'])))->save();

        $profile = UserProfile::firstOrCreate(['user_id' => $user->id]);
        $profile->fill(array_intersect_key($data, array_flip([
            'country_code', 'date_of_birth', 'learning_objective', 'profession', 'interests', 'favourite_topics',
        ])));
        if (array_key_exists('native_language', $data)) {
            $profile->native_language_id = $data['native_language']
                ? Language::where('code', $data['native_language'])->value('id') : null;
        }
        if (array_key_exists('target_language', $data)) {
            $profile->target_language_id = Language::where('code', $data['target_language'])->value('id');
        }
        $profile->save();

        return $this->ok(new UserResource($user->fresh()->load('profile', 'settings', 'learnerProfile.cefrLevel')));
    }

    public function updateSettings(Request $request)
    {
        $data = $request->validate([
            'daily_target_minutes' => ['sometimes', 'integer', 'min:5', 'max:180'],
            'weekly_goal_minutes' => ['sometimes', 'integer', 'min:15', 'max:1260'],
            'preferred_study_time' => ['sometimes', 'nullable', 'date_format:H:i'],
            'theme' => ['sometimes', Rule::in(['dark', 'light', 'system'])],
            'notifications_email' => ['sometimes', 'boolean'],
            'notifications_push' => ['sometimes', 'boolean'],
            'reminder_enabled' => ['sometimes', 'boolean'],
            'speech_consent_given' => ['sometimes', 'boolean'],
            'speech_retention_days' => ['sometimes', 'integer', 'min:1', 'max:730'],
            'allow_speech_for_model_improvement' => ['sometimes', 'boolean'],
        ]);

        $settings = UserSetting::firstOrCreate(['user_id' => $request->user()->id]);

        // Consent is timestamped when granted; withdrawing clears the timestamp
        // so retention jobs can act on the change.
        if (array_key_exists('speech_consent_given', $data)) {
            $data['speech_consent_at'] = $data['speech_consent_given'] ? now() : null;
        }
        $settings->fill($data)->save();

        return $this->ok(new UserResource($request->user()->fresh()->load('settings', 'profile')));
    }

    public function uploadAvatar(Request $request)
    {
        $request->validate([
            'avatar' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ]);

        $user = $request->user();
        $old = $user->avatar_path;
        $path = $request->file('avatar')->store('avatars', 'public');
        $user->update(['avatar_path' => $path]);

        if ($old) {
            Storage::disk('public')->delete($old);
        }

        return $this->ok(['avatar_url' => url('storage/'.$path)]);
    }

    /** Data export request (spec 45). Processed asynchronously. */
    public function requestExport(Request $request)
    {
        $req = PrivacyRequest::create([
            'user_id' => $request->user()->id,
            'type' => 'export',
            'status' => 'pending',
        ]);

        return $this->created(['request_id' => $req->id, 'status' => $req->status]);
    }

    /**
     * What this person has asked for, and where each request got to.
     *
     * Without this an export is a promise into the dark: the row was created,
     * the file was written, and nobody could see either.
     */
    public function privacyRequests(Request $request)
    {
        return $this->ok(
            PrivacyRequest::where('user_id', $request->user()->id)
                ->orderByDesc('id')
                ->get()
                ->map(fn (PrivacyRequest $r) => [
                    'id' => $r->id,
                    'type' => $r->type,
                    'status' => $r->status,
                    'requested_at' => $r->created_at?->toIso8601String(),
                    'completed_at' => $r->completed_at?->toIso8601String(),
                    'expires_at' => $r->expires_at?->toIso8601String(),
                    'downloadable' => $r->type === 'export'
                        && $r->status === 'completed'
                        && $r->export_path !== null,
                    'error' => $r->error,
                ]),
        );
    }

    /** The export file itself, for the person who asked for it and nobody else. */
    public function downloadExport(Request $request, PrivacyRequest $privacyRequest)
    {
        abort_unless($privacyRequest->user_id === $request->user()->id, 404);

        if ($privacyRequest->export_path === null
            || $privacyRequest->status !== 'completed') {
            return $this->fail(
                'export_not_ready',
                'This export is not ready, or its download window has closed.',
                404,
            );
        }

        return Storage::disk(PrivacyRequestService::DISK)->download(
            $privacyRequest->export_path,
            'zaban-export.json',
        );
    }

    public function requestDeletion(Request $request)
    {
        $request->validate(['confirm' => ['required', 'accepted']]);

        $req = PrivacyRequest::create([
            'user_id' => $request->user()->id,
            'type' => 'delete_account',
            'status' => 'pending',
        ]);
        // Sessions end immediately even though erasure is processed async.
        $request->user()->tokens()->delete();

        return $this->created(['request_id' => $req->id, 'status' => $req->status]);
    }
}
