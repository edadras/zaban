<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EntitlementUsage extends Model
{
    protected $table = 'entitlement_usage';

    protected $fillable = [
        'user_id',
        'feature',
        'period_start',
        'period',
        'used',
        'limit_value',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
        ];
    }
}
