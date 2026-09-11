<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IngestionJob extends Model
{
    protected $table = 'ingestion_jobs';

    protected $fillable = [
        'source_document_id',
        'started_by',
        'status',
        'current_stage',
        'started_at',
        'finished_at',
        'stats',
    ];

    protected function casts(): array
    {
        return [
            'stats' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(SourceDocument::class, 'source_document_id');
    }

    public function stages(): HasMany
    {
        return $this->hasMany(IngestionStage::class);
    }
}
