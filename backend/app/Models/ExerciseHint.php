<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExerciseHint extends Model
{
    protected $table = 'exercise_hints';

    protected $fillable = ['exercise_id', 'level', 'text'];

    public function exercise(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Exercise::class);
    }
}
