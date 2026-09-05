<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlacementSession extends Model
{
    protected $table = 'placement_sessions';

    protected $fillable = [
        'user_id',
        'language_id',
        'status',
        'ability',
        'ability_se',
        'items_administered',
        'max_items',
        'result_cefr_level_id',
        'result_confidence',
        'stop_reason',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'ability' => 'float',
            'ability_se' => 'float',
            'result_confidence' => 'float',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function skillStates(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PlacementSkillState::class);
    }

    public function responses(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PlacementResponse::class);
    }

    public function resultLevel(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(CefrLevel::class, 'result_cefr_level_id');
    }
}
