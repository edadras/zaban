<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CefrLevel extends Model
{
    protected $table = 'cefr_levels';

    protected $fillable = [
        'code',
        'ordinal',
        'name',
        'description',
        'ability_min',
        'ability_max',
    ];

    protected function casts(): array
    {
        return [
            'ability_min' => 'float',
            'ability_max' => 'float',
        ];
    }
}
