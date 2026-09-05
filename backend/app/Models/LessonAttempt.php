<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LessonAttempt extends Model
{
    protected $table = 'lesson_attempts';

    protected $fillable = [
        'user_id',
        'lesson_id',
        'learning_session_id',
        'status',
        'blocks_total',
        'blocks_completed',
        'score',
        'duration_seconds',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'score' => 'float',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function lesson(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }
}
