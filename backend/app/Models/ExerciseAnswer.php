<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExerciseAnswer extends Model
{
    protected $table = 'exercise_answers';

    protected $fillable = [
        'exercise_id',
        'blank_index',
        'value',
        'match_mode',
        'is_primary',
        'credit',
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'credit' => 'float',
        ];
    }

    public function exercise(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Exercise::class);
    }
}
