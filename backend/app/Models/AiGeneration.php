<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiGeneration extends Model
{
    protected $table = 'ai_generations';

    protected $fillable = [
        'ai_request_id',
        'output_type',
        'prompt',
        'negative_prompt',
        'output_text',
        'output_json',
        'media_asset_id',
        'output_url',
        'seed',
        'parameters',
        'provider_metadata',
        'reuse_count',
    ];

    protected function casts(): array
    {
        return [
            'output_json' => 'array',
            'parameters' => 'array',
            'provider_metadata' => 'array',
        ];
    }

    public function request(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(AiRequest::class, 'ai_request_id');
    }

    public function mediaAsset(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'media_asset_id');
    }
}
