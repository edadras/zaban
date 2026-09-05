<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LearnerProfile extends Model
{
    protected $table = 'learner_profiles';

    protected $fillable = [
        'user_id',
        'language_id',
        'current_cefr_level_id',
        'active_course_version_id',
        'ability',
        'ability_se',
        'placement_status',
        'placed_at',
        'xp',
        'streak_days',
        'longest_streak_days',
        'last_study_date',
        'total_study_minutes',
        'mastery_score',
        'frustration_index',
        'last_session_at',
    ];

    protected function casts(): array
    {
        return [
            'ability' => 'float',
            'ability_se' => 'float',
            'mastery_score' => 'float',
            'frustration_index' => 'float',
            'placed_at' => 'datetime',
            'last_study_date' => 'date',
            'last_session_at' => 'datetime',
        ];
    }

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function cefrLevel(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(CefrLevel::class, 'current_cefr_level_id');
    }

    public function courseVersion(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(CourseVersion::class, 'active_course_version_id');
    }
}
