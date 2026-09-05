<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExerciseOption extends Model
{
    protected $table = 'exercise_options';

    protected $fillable = [
        'exercise_id',
        'position',
        'text',
        'media_asset_id',
        'is_correct',
        'distractor_rationale',
    ];

    protected function casts(): array
    {
        return [
            'is_correct' => 'boolean',
        ];
    }

    public function exercise(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Exercise::class);
    }
}
