<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DailyProgress extends Model
{
    protected $table = 'daily_progress';

    protected $fillable = [
        'user_id',
        'date',
        'study_seconds',
        'sessions_completed',
        'lessons_completed',
        'exercises_attempted',
        'exercises_correct',
        'reviews_completed',
        'new_concepts',
        'concepts_mastered',
        'speaking_seconds',
        'xp_earned',
        'goal_met',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'goal_met' => 'boolean',
        ];
    }
}
