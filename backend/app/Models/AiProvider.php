<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    public function models(): HasMany
    {
        return $this->hasMany(AiModel::class);
    }
}
