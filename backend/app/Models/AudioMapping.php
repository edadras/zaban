<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AudioMapping extends Model
{
    protected $table = 'audio_mappings';

    protected $fillable = [
        'audio_asset_id',
        'mappable_type',
        'mappable_id',
        'confidence',
        'method',
        'evidence',
        'review_status',
        'reviewed_by',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'evidence' => 'array',
            'confidence' => 'float',
            'reviewed_at' => 'datetime',
        ];
    }

    public function audioAsset(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(AudioAsset::class, 'audio_asset_id');
    }

    public function mappable(): \Illuminate\Database\Eloquent\Relations\MorphTo
    {
        return $this->morphTo();
    }
}
