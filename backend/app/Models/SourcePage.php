<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SourcePage extends Model
{
    protected $table = 'source_pages';

    protected $fillable = [
        'source_file_id',
        'page_number',
        'text',
        'char_count',
        'used_vision',
        'page_image_media_asset_id',
        'status',
        'error',
    ];

    protected function casts(): array
    {
        return ['used_vision' => 'boolean'];
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(SourceFile::class, 'source_file_id');
    }

    public function segments(): HasMany
    {
        return $this->hasMany(SourceSegment::class, 'source_page_id');
    }
}
