<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiUsage extends Model
{
    protected $table = 'ai_usage';

    protected $fillable = [
        'user_id',
        'feature',
        'usage_date',
        'request_count',
        'input_tokens',
        'output_tokens',
        'credits_used',
        'estimated_cost',
    ];

    protected function casts(): array
    {
        return [
            'usage_date' => 'date',
            'credits_used' => 'float',
            'estimated_cost' => 'float',
        ];
    }
}
