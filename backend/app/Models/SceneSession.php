<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SceneSession extends Model
{
    protected $table = 'scene_sessions';

    protected $fillable = [
        'user_id',
        'scene_id',
        'role',
        'mode',
        'status',
        'position',
        'attempts',
        'cleared',
        'score',
        'summary',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'summary' => 'array',
            'score' => 'float',
            'completed_at' => 'datetime',
        ];
    }

    public function scene(): BelongsTo
    {
        return $this->belongsTo(Scene::class);
    }

    public function sceneAttempts(): HasMany
    {
        return $this->hasMany(SceneAttempt::class);
    }
}
