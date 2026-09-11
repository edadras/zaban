<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SceneAttempt extends Model
{
    protected $table = 'scene_attempts';

    protected $fillable = [
        'scene_session_id',
        'scene_beat_id',
        'kind',
        'given',
        'speech_attempt_id',
        'similarity',
        'accepted',
        'try_number',
        'detail',
    ];

    protected function casts(): array
    {
        return [
            'accepted' => 'boolean',
            'detail' => 'array',
        ];
    }

    public function beat(): BelongsTo
    {
        return $this->belongsTo(SceneBeat::class, 'scene_beat_id');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(SceneSession::class, 'scene_session_id');
    }
}
