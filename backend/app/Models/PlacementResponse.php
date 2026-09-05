<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlacementResponse extends Model
{
    protected $table = 'placement_responses';

    protected $fillable = [
        'placement_session_id',
        'exercise_id',
        'skill_id',
        'sequence',
        'response',
        'is_correct',
        'score',
        'speech_attempt_id',
        'response_ms',
        'ability_before',
        'ability_after',
        'se_before',
        'se_after',
        'item_information',
        'presented_at',
        'answered_at',
    ];

    protected function casts(): array
    {
        return [
            'response' => 'array',
            'is_correct' => 'boolean',
            'score' => 'float',
            'ability_before' => 'float',
            'ability_after' => 'float',
            'se_before' => 'float',
            'se_after' => 'float',
            'item_information' => 'float',
            'presented_at' => 'datetime',
            'answered_at' => 'datetime',
        ];
    }

    public function exercise(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Exercise::class);
    }

    public function skill(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Skill::class);
    }
}
