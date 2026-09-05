<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Dialogue extends Model
{
    use SoftDeletes;

    protected $table = 'dialogues';

    protected $fillable = [
        'language_id',
        'cefr_level_id',
        'topic_id',
        'title',
        'setting',
        'summary',
        'audio_media_asset_id',
        'generation_method',
        'copyright_status',
        'source_document_id',
        'source_page',
    ];

    public function turns(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(DialogueTurn::class);
    }
}
