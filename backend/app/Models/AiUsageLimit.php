<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiUsageLimit extends Model
{
    protected $table = 'ai_usage_limits';

    protected $fillable = [
        'plan_id',
        'user_id',
        'feature',
        'period',
        'max_requests',
        'max_cost',
        'max_credits',
        'on_exceed',
    ];

    protected function casts(): array
    {
        return [
            'max_cost' => 'float',
            'max_credits' => 'float',
        ];
    }
}
