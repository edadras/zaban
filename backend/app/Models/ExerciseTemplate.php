<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExerciseTemplate extends Model
{
    protected $table = 'exercise_templates';

    protected $fillable = [
        'code',
        'name',
        'description',
        'block_type',
        'skill_codes',
        'is_productive',
        'supports_audio',
        'supports_image',
        'payload_schema',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'skill_codes' => 'array',
            'payload_schema' => 'array',
            'is_productive' => 'boolean',
            'supports_audio' => 'boolean',
            'supports_image' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function exercises(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Exercise::class);
    }
}
