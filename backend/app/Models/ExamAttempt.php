<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExamAttempt extends Model
{
    protected $table = 'exam_attempts';

    protected $fillable = [
        'user_id',
        'exam_type_id',
        'mode',
        'status',
        'estimated_score',
        'estimated_cefr_level_id',
        'is_ai_estimated',
        'duration_seconds',
        'time_management',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'estimated_score' => 'float',
            'is_ai_estimated' => 'boolean',
            'time_management' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function examType(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(ExamType::class, 'exam_type_id');
    }

    public function sectionAttempts(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ExamSectionAttempt::class);
    }

    public function scores(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ExamScore::class);
    }
}
