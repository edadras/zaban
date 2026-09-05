<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExerciseAttempt extends Model
{
    protected $table = 'exercise_attempts';

    protected $fillable = [
        'user_id',
        'exercise_id',
        'learning_session_id',
        'lesson_attempt_id',
        'session_activity_id',
        'response',
        'is_correct',
        'score',
        'hints_used',
        'attempt_number',
        'response_ms',
        'ability_at_attempt',
        'predicted_success',
        'feedback',
        'answered_at',
    ];

    protected function casts(): array
    {
        return [
            'response' => 'array',
            'feedback' => 'array',
            'is_correct' => 'boolean',
            'score' => 'float',
            'ability_at_attempt' => 'float',
            'predicted_success' => 'float',
            'answered_at' => 'datetime',
        ];
    }

    public function exercise(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Exercise::class);
    }

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
