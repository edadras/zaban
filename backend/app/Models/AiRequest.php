<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiRequest extends Model
{
    protected $table = 'ai_requests';

    protected $fillable = [
        'user_id',
        'ai_provider_id',
        'ai_model_id',
        'ai_prompt_id',
        'feature',
        'status',
        'request_id',
        'idempotency_key',
        'cache_key',
        'served_from_cache',
        'input_tokens',
        'output_tokens',
        'credits_used',
        'estimated_cost',
        'duration_ms',
        'attempt',
        'fallback_of_id',
        'error',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'served_from_cache' => 'boolean',
            'credits_used' => 'float',
            'estimated_cost' => 'float',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function provider(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(AiProvider::class, 'ai_provider_id');
    }

    public function aiModel(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(AiModel::class, 'ai_model_id');
    }

    public function generation(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(AiGeneration::class);
    }
}
