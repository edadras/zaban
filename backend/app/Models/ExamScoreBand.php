<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExamScoreBand extends Model
{
    protected $table = 'exam_score_bands';

    protected $fillable = [
        'exam_type_id',
        'cefr_level_id',
        'score_from',
        'score_to',
    ];

    protected function casts(): array
    {
        return [
            'score_from' => 'float',
            'score_to' => 'float',
        ];
    }

    public function cefrLevel(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(CefrLevel::class, 'cefr_level_id');
    }
}
