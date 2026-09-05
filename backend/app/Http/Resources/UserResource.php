<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role,
            'avatar_url' => $this->avatar_path ? url('storage/'.$this->avatar_path) : null,
            'locale' => $this->locale,
            'timezone' => $this->timezone,
            'email_verified' => $this->email_verified_at !== null,
            'profile' => $this->whenLoaded('profile', fn () => [
                'native_language_id' => $this->profile->native_language_id,
                'target_language_id' => $this->profile->target_language_id,
                'country_code' => $this->profile->country_code,
                'learning_objective' => $this->profile->learning_objective,
                'profession' => $this->profile->profession,
                'interests' => $this->profile->interests,
            ]),
            'settings' => $this->whenLoaded('settings', fn () => [
                'daily_target_minutes' => $this->settings->daily_target_minutes,
                'weekly_goal_minutes' => $this->settings->weekly_goal_minutes,
                'preferred_study_time' => $this->settings->preferred_study_time,
                'theme' => $this->settings->theme,
                'notifications_email' => (bool) $this->settings->notifications_email,
                'notifications_push' => (bool) $this->settings->notifications_push,
                'reminder_enabled' => (bool) $this->settings->reminder_enabled,
                'speech_consent_given' => (bool) $this->settings->speech_consent_given,
                'speech_consent_at' => $this->settings->speech_consent_at?->toIso8601String(),
                'speech_retention_days' => (int) $this->settings->speech_retention_days,
                'allow_speech_for_model_improvement' => (bool) $this->settings->allow_speech_for_model_improvement,
            ]),
            'learner' => $this->whenLoaded('learnerProfile', fn () => [
                'cefr_level' => $this->learnerProfile->relationLoaded('cefrLevel')
                    ? $this->learnerProfile->cefrLevel?->code : null,
                'placement_status' => $this->learnerProfile->placement_status,
                'ability' => (float) $this->learnerProfile->ability,
                'xp' => (int) $this->learnerProfile->xp,
                'streak_days' => (int) $this->learnerProfile->streak_days,
                'mastery_score' => (float) $this->learnerProfile->mastery_score,
                'total_study_minutes' => (int) $this->learnerProfile->total_study_minutes,
            ]),
        ];
    }
}
