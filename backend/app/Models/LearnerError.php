<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LearnerError extends Model
{
    protected $table = 'learner_errors';

    protected $fillable = [
        'user_id',
        'concept_id',
        'skill_id',
        'error_type',
        'error_subtype',
        'input',
        'expected',
        'note',
        'severity',
        'confidence',
        'occurrence_count',
        'first_seen_at',
        'last_seen_at',
        'resolved_at',
        'remediation_count',
    ];

    protected function casts(): array
    {
        return [
            'confidence' => 'float',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function concept(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }

    public function skill(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Skill::class);
    }
}
