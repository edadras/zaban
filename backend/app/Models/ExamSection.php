<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExamSection extends Model
{
    protected $table = 'exam_sections';

    protected $fillable = [
        'exam_type_id',
        'skill_id',
        'code',
        'name',
        'position',
        'duration_minutes',
        'question_count',
        'scoring_criteria',
    ];

    protected function casts(): array
    {
        return [
            'scoring_criteria' => 'array',
        ];
    }

    public function examType(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(ExamType::class, 'exam_type_id');
    }

    public function skill(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Skill::class);
    }

    public function taskTypes(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ExamTaskType::class);
    }
}
