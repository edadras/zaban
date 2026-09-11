<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WordForm extends Model
{
    protected $table = 'word_forms';

    protected $fillable = [
        'vocabulary_item_id',
        'form',
        'normalised',
        'form_type',
        'is_irregular',
    ];

    protected function casts(): array
    {
        return [
            'is_irregular' => 'boolean',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(VocabularyItem::class, 'vocabulary_item_id');
    }
}
