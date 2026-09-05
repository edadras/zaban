<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Phoneme extends Model
{
    protected $table = 'phonemes';

    protected $fillable = [
        'language_id',
        'ipa',
        'arpabet',
        'type',
        'features',
        'articulation_hint',
    ];

    protected function casts(): array
    {
        return [
            'features' => 'array',
        ];
    }
}
