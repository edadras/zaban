<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Passage extends Model
{
    use SoftDeletes;

    protected $table = 'passages';

    protected $fillable = [
        'language_id',
        'modality',
        'title',
        'body',
        'cefr_level_id',
        'topic_id',
        'word_count',
        'readability_score',
        'words_per_minute',
        'audio_media_asset_id',
        'genre',
        'generation_method',
        'copyright_status',
        'source_document_id',
        'source_page',
    ];

    protected function casts(): array
    {
        return [
            'readability_score' => 'float',
        ];
    }

    public function segments(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PassageSegment::class);
    }
}
