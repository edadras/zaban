<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExamScore extends Model
{
    protected $table = 'exam_scores';

    protected $fillable = [
        'exam_attempt_id',
        'exam_section_attempt_id',
        'criterion',
        'score',
        'rationale',
        'evidence',
    ];

    protected function casts(): array
    {
        return [
            'score' => 'float',
            'evidence' => 'array',
        ];
    }
}
