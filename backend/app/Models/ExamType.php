<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExamType extends Model
{
    protected $table = 'exam_types';

    protected $fillable = [
        'language_id',
        'code',
        'name',
        'description',
        'score_type',
        'score_min',
        'score_max',
        'score_step',
        'total_minutes',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'score_min' => 'float',
            'score_max' => 'float',
            'score_step' => 'float',
            'is_active' => 'boolean',
        ];
    }

    public function sections(): HasMany
    {
        return $this->hasMany(ExamSection::class);
    }

    public function bands(): HasMany
    {
        return $this->hasMany(ExamScoreBand::class);
    }
}
