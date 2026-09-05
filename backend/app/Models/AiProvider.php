<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiProvider extends Model
{
    protected $table = 'ai_providers';

    protected $fillable = [
        'code',
        'name',
        'capabilities',
        'driver',
        'is_active',
        'priority',
        'config',
    ];

    protected function casts(): array
    {
        return [
            'capabilities' => 'array',
            'config' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function models(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(AiModel::class);
    }
}
