<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExamTaskType extends Model
{
    protected $table = 'exam_task_types';

    protected $fillable = [
        'exam_section_id',
        'code',
        'name',
        'description',
        'exercise_template_id',
        'typical_count',
    ];

    public function section(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(ExamSection::class, 'exam_section_id');
    }

    public function tasks(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ExamTask::class);
    }
}
