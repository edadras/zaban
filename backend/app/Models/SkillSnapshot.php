<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SkillSnapshot extends Model
{
    protected $table = 'skill_snapshots';

    protected $fillable = [
        'user_id',
        'skill_id',
        'snapshot_date',
        'ability',
        'ability_se',
        'cefr_level_id',
        'mastery_score',
        'concepts_tracked',
        'concepts_mastered',
    ];

    protected function casts(): array
    {
        return [
            'snapshot_date' => 'date',
            'ability' => 'float',
            'ability_se' => 'float',
            'mastery_score' => 'float',
        ];
    }

    public function skill(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Skill::class);
    }
}
