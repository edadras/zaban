<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContentReview extends Model
{
    protected $table = 'content_reviews';

    protected $fillable = [
        'reviewable_type',
        'reviewable_id',
        'status',
        'validation_score',
        'validation_results',
        'auto_publishable',
        'reviewed_by',
        'reviewed_at',
        'reviewer_notes',
        'rejection_reason',
    ];

    protected function casts(): array
    {
        return [
            'validation_results' => 'array',
            'auto_publishable' => 'boolean',
            'reviewed_at' => 'datetime',
            'validation_score' => 'float',
        ];
    }

    public function reviewable(): \Illuminate\Database\Eloquent\Relations\MorphTo
    {
        return $this->morphTo();
    }
}
