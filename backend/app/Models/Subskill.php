<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Subskill extends Model
{
    protected $table = 'subskills';

    protected $fillable = [
        'skill_id',
        'code',
        'name',
        'description',
        'position',
    ];

    public function skill(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Skill::class);
    }
}
