<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserSetting extends Model
{
    protected $table = 'user_settings';

    protected $fillable = [
        'user_id',
        'daily_target_minutes',
        'weekly_goal_minutes',
        'preferred_study_time',
        'theme',
        'notifications_email',
        'notifications_push',
        'reminder_enabled',
        'speech_consent_given',
        'speech_consent_at',
        'speech_retention_days',
        'allow_speech_for_model_improvement',
    ];

    protected function casts(): array
    {
        return [
            'notifications_email' => 'boolean',
            'notifications_push' => 'boolean',
            'reminder_enabled' => 'boolean',
            'speech_consent_given' => 'boolean',
            'speech_consent_at' => 'datetime',
            'allow_speech_for_model_improvement' => 'boolean',
        ];
    }

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
