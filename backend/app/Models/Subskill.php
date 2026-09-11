<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    public function skill(): BelongsTo
    {
        return $this->belongsTo(Skill::class);
    }
}
