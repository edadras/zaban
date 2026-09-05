<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReviewHistory extends Model
{
    protected $table = 'review_history';

    protected $fillable = [
        'user_id',
        'concept_id',
        'was_successful',
        'quality',
        'mastery_before',
        'mastery_after',
        'interval_days_before',
        'interval_days_after',
        'response_ms',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'was_successful' => 'boolean',
            'quality' => 'float',
            'mastery_before' => 'float',
            'mastery_after' => 'float',
            'reviewed_at' => 'datetime',
        ];
    }
}
