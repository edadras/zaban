<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LearningSession extends Model
{
    protected $table = 'learning_sessions';

    protected $fillable = [
        'user_id',
        'course_version_id',
        'status',
        'kind',
        'composition',
        'planned_minutes',
        'actual_seconds',
        'activities_planned',
        'activities_completed',
        'xp_earned',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'composition' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(SessionActivity::class);
    }
}
