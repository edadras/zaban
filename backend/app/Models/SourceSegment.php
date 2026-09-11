<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SourceSegment extends Model
{
    protected $table = 'source_segments';

    protected $fillable = [
        'source_document_id',
        'source_page_id',
        'parent_id',
        'segment_type',
        'position',
        'label',
        'text',
        'bbox',
        'cefr_level_id',
        'topic_id',
        'classification_confidence',
    ];

    protected function casts(): array
    {
        return ['bbox' => 'array', 'classification_confidence' => 'float'];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(SourceDocument::class, 'source_document_id');
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(SourcePage::class, 'source_page_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }
}
