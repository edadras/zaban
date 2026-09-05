<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConversationSession extends Model
{
    protected $table = 'conversation_sessions';

    protected $fillable = [
        'user_id',
        'conversation_scenario_id',
        'learning_session_id',
        'mode',
        'status',
        'turn_count',
        'duration_seconds',
        'objectives_met',
        'summary',
        'overall_score',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'objectives_met' => 'array',
            'summary' => 'array',
            'overall_score' => 'float',
            'completed_at' => 'datetime',
        ];
    }

    public function scenario(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(ConversationScenario::class, 'conversation_scenario_id');
    }

    public function turns(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ConversationTurn::class);
    }
}
