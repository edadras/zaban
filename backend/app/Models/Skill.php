<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Skill extends Model
{
    protected $table = 'skills';

    protected $fillable = [
        'code',
        'name',
        'description',
        'is_productive',
        'assessed_in_placement',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'is_productive' => 'boolean',
            'assessed_in_placement' => 'boolean',
        ];
    }

    public function subskills(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Subskill::class);
    }
}
