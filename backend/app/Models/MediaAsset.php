<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class MediaAsset extends Model
{
    use SoftDeletes;

    protected $table = 'media_assets';

    protected $fillable = [
        'disk',
        'path',
        'type',
        'mime',
        'bytes',
        'width',
        'height',
        'duration_ms',
        'checksum',
        'origin',
        'ai_generation_id',
        'copyright_status',
        'attribution',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }
}
