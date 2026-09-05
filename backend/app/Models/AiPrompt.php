<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiPrompt extends Model
{
    protected $table = 'ai_prompts';

    protected $fillable = [
        'key',
        'version',
        'name',
        'purpose',
        'system_template',
        'user_template',
        'negative_template',
        'variables',
        'output_schema',
        'temperature',
        'max_tokens',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'variables' => 'array',
            'output_schema' => 'array',
            'temperature' => 'float',
            'is_active' => 'boolean',
        ];
    }
}
