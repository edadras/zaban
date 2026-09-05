<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlanEntitlement extends Model
{
    protected $table = 'plan_entitlements';

    protected $fillable = [
        'plan_id',
        'feature',
        'is_enabled',
        'limit_value',
        'limit_period',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
        ];
    }

    public function plan(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
