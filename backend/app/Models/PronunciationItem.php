<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PronunciationItem extends Model
{
    protected $table = 'pronunciation_items';

    protected $fillable = [
        'language_id',
        'vocabulary_item_id',
        'text',
        'ipa',
        'accent',
        'cefr_level_id',
        'media_asset_id',
    ];

    public function item(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(VocabularyItem::class, 'vocabulary_item_id');
    }
}
