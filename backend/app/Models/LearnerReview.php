<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LearnerReview extends Model
{
    protected $table = 'learner_reviews';

    protected $fillable = [
        'user_id',
        'learner_concept_id',
        'scheduled_for',
        'status',
        'trigger',
        'completed_at',
        'was_successful',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_for' => 'datetime',
            'completed_at' => 'datetime',
            'was_successful' => 'boolean',
        ];
    }

    public function learnerConcept(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(LearnerConcept::class, 'learner_concept_id');
    }
}
