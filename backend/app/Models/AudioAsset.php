<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AudioAsset extends Model
{
    protected $table = 'audio_assets';

    protected $fillable = [
        'source_file_id',
        'media_asset_id',
        'duration_ms',
        'codec',
        'sample_rate',
        'channels',
        'transcript',
        'word_timestamps',
        'transcription_status',
        'detected_language',
        'speaker_count',
    ];

    protected function casts(): array
    {
        return [
            'word_timestamps' => 'array',
        ];
    }

    public function mediaAsset(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'media_asset_id');
    }

    public function mappings(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(AudioMapping::class);
    }
}
