<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExamSectionAttempt extends Model
{
    protected $table = 'exam_section_attempts';

    protected $fillable = [
        'exam_attempt_id',
        'exam_section_id',
        'status',
        'raw_score',
        'estimated_score',
        'duration_seconds',
        'ran_out_of_time',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'raw_score' => 'float',
            'estimated_score' => 'float',
            'ran_out_of_time' => 'boolean',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function section(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(ExamSection::class, 'exam_section_id');
    }
}
