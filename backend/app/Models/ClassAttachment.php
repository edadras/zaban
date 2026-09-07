<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** A picture, a video or a recording hanging off a post or a piece of work. */
class ClassAttachment extends Model
{
    public const KINDS = ['image', 'video', 'audio', 'pdf', 'file'];

    protected $fillable = [
        'attachable_type', 'attachable_id', 'media_asset_id',
        'uploaded_by', 'kind', 'caption', 'position',
    ];

    protected function casts(): array
    {
        return ['position' => 'integer'];
    }

    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    public function media(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'media_asset_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
