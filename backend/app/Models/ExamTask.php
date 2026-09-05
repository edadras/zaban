<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ExamTask extends Model
{
    use SoftDeletes;

    protected $table = 'exam_tasks';

    protected $fillable = [
        'exam_task_type_id',
        'passage_id',
        'production_prompt_id',
        'title',
        'instructions',
        'position',
        'time_limit_seconds',
        'status',
        'generation_method',
    ];

    public function taskType(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(ExamTaskType::class, 'exam_task_type_id');
    }
}
