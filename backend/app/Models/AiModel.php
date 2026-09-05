<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiModel extends Model
{
    protected $table = 'ai_models';

    protected $fillable = [
        'ai_provider_id',
        'code',
        'name',
        'modality',
        'is_active',
        'is_fallback',
        'context_tokens',
        'input_cost_per_1k',
        'output_cost_per_1k',
        'unit_cost',
        'credit_cost',
        'capabilities',
    ];

    protected function casts(): array
    {
        return [
            'capabilities' => 'array',
            'is_active' => 'boolean',
            'is_fallback' => 'boolean',
            'input_cost_per_1k' => 'float',
            'output_cost_per_1k' => 'float',
            'unit_cost' => 'float',
            'credit_cost' => 'float',
        ];
    }

    public function provider(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(AiProvider::class, 'ai_provider_id');
    }
}
