<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExerciseHint extends Model
{
    protected $table = 'exercise_hints';

    protected $fillable = ['exercise_id', 'level', 'text'];

    public function exercise(): BelongsTo
    {
        return $this->belongsTo(Exercise::class);
    }
}
