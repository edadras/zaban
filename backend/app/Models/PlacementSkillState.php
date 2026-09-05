<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlacementSkillState extends Model
{
    protected $table = 'placement_skill_states';

    protected $fillable = [
        'placement_session_id',
        'skill_id',
        'ability',
        'ability_se',
        'items_administered',
        'min_items',
        'max_items',
        'target_se',
        'is_complete',
        'result_cefr_level_id',
        'confidence',
    ];

    protected function casts(): array
    {
        return [
            'ability' => 'float',
            'ability_se' => 'float',
            'target_se' => 'float',
            'is_complete' => 'boolean',
            'confidence' => 'float',
        ];
    }

    public function skill(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Skill::class);
    }

    public function session(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(PlacementSession::class, 'placement_session_id');
    }
}
