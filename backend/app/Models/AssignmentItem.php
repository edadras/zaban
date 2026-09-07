<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One question in a piece of homework.
 *
 * Either it points at an exercise in the course - and then the wording, the
 * options and the answer are the course's, not a retyped copy - or it carries
 * its own.
 */
class AssignmentItem extends Model
{
    protected $fillable = [
        'assignment_id', 'exercise_id', 'prompt', 'options',
        'correct_options', 'points', 'position',
    ];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'correct_options' => 'array',
            'points' => 'integer',
            'position' => 'integer',
        ];
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class);
    }

    public function exercise(): BelongsTo
    {
        return $this->belongsTo(Exercise::class);
    }
}
