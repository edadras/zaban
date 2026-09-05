<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SourceFile extends Model
{
    protected $table = 'source_files';

    protected $fillable = [
        'source_document_id',
        'parent_source_file_id',
        'disk',
        'path',
        'original_name',
        'relative_path',
        'kind',
        'mime',
        'bytes',
        'checksum',
        'sequence',
        'status',
    ];

    public function document(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(SourceDocument::class, 'source_document_id');
    }
}
