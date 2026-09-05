<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SourceDocument extends Model
{
    use SoftDeletes;

    protected $table = 'source_documents';

    protected $fillable = [
        'title',
        'publisher',
        'isbn',
        'language_id',
        'cefr_level_id',
        'copyright_status',
        'license_note',
        'uploaded_by',
        'status',
    ];

    public function files(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(SourceFile::class);
    }

    public function language(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Language::class);
    }

    public function cefrLevel(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(CefrLevel::class, 'cefr_level_id');
    }
}
