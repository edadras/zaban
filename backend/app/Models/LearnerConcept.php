<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LearnerConcept extends Model
{
    protected $table = 'learner_concepts';

    protected $fillable = [
        'user_id',
        'concept_id',
        'mastery_score',
        'confidence',
        'exposure_count',
        'correct_count',
        'incorrect_count',
        'hint_count',
        'consecutive_correct',
        'avg_response_ms',
        'difficulty_performance',
        'memory_strength',
        'ease_factor',
        'interval_days',
        'repetition_number',
        'next_review_at',
        'decay_score',
        'first_seen_at',
        'last_seen_at',
        'last_success_at',
        'mastered_at',
    ];

    protected function casts(): array
    {
        return [
            'mastery_score' => 'float',
            'confidence' => 'float',
            'memory_strength' => 'float',
            'ease_factor' => 'float',
            'decay_score' => 'float',
            'difficulty_performance' => 'array',
            'next_review_at' => 'datetime',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'last_success_at' => 'datetime',
            'mastered_at' => 'datetime',
        ];
    }

    public function concept(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
