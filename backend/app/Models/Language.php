<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Language extends Model
{
    protected $table = 'languages';

    protected $fillable = [
        'code',
        'name',
        'native_name',
        'direction',
        'is_learnable',
        'is_interface',
    ];

    protected function casts(): array
    {
        return [
            'is_learnable' => 'boolean',
            'is_interface' => 'boolean',
        ];
    }
}
