<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Plan extends Model
{
    use SoftDeletes;

    protected $table = 'plans';

    protected $fillable = [
        'code',
        'name',
        'description',
        'interval',
        'interval_count',
        'trial_days',
        'position',
        'is_active',
        'is_public',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_public' => 'boolean',
        ];
    }

    public function prices(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PlanPrice::class);
    }

    public function entitlements(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PlanEntitlement::class);
    }
}
