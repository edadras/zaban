<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LearnerSkillState extends Model
{
    protected $table = 'learner_skill_states';

    protected $fillable = [
        'user_id',
        'skill_id',
        'cefr_level_id',
        'ability',
        'ability_se',
        'confidence',
        'attempt_count',
        'last_assessed_at',
    ];

    protected function casts(): array
    {
        return [
            'ability' => 'float',
            'ability_se' => 'float',
            'confidence' => 'float',
            'last_assessed_at' => 'datetime',
        ];
    }

    public function skill(): BelongsTo
    {
        return $this->belongsTo(Skill::class);
    }

    public function cefrLevel(): BelongsTo
    {
        return $this->belongsTo(CefrLevel::class, 'cefr_level_id');
    }
}
