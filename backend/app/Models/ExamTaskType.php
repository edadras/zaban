<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    public function section(): BelongsTo
    {
        return $this->belongsTo(ExamSection::class, 'exam_section_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(ExamTask::class);
    }
}
