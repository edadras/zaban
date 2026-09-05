<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExerciseExplanation extends Model
{
    protected $table = 'exercise_explanations';

    protected $fillable = [
        'exercise_id', 'language_id', 'cefr_level_id', 'text', 'generation_method',
    ];

    public function exercise(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Exercise::class);
    }
}
