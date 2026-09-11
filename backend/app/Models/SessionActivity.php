<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class SessionActivity extends Model
{
    protected $table = 'session_activities';

    protected $fillable = [
        'learning_session_id',
        'position',
        'phase',
        'phase_position',
        'activity_type',
        'subject_type',
        'subject_id',
        'concept_id',
        'selection_reason',
        'rationale',
        'priority_score',
        'predicted_success',
        'status',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'selection_reason' => 'array',
            'priority_score' => 'float',
            'predicted_success' => 'float',
            'completed_at' => 'datetime',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(LearningSession::class, 'learning_session_id');
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function concept(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }
}
