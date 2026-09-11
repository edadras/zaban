<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Character extends Model
{
    protected $table = 'characters';

    protected $fillable = [
        'slug',
        'name',
        'persona',
        'accent',
        'voice_id',
        'avatar_media_asset_id',
        'appearance_prompt',
        'soul_id',
        'reference_media_asset_id',
        'model_3d_url',
        'model_3d_status',
    ];

    public function referenceImage(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'reference_media_asset_id');
    }

    /** True when this character can be reproduced identically, not just described. */
    public function hasStableIdentity(): bool
    {
        return $this->soul_id !== null || $this->reference_media_asset_id !== null;
    }
}
