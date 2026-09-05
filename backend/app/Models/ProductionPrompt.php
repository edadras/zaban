<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductionPrompt extends Model
{
    use SoftDeletes;

    protected $table = 'production_prompts';

    protected $fillable = [
        'language_id',
        'modality',
        'task_type',
        'title',
        'prompt',
        'guidance',
        'cefr_level_id',
        'topic_id',
        'min_words',
        'max_words',
        'prep_seconds',
        'response_seconds',
        'rubric',
        'generation_method',
    ];

    protected function casts(): array
    {
        return [
            'rubric' => 'array',
        ];
    }
}
