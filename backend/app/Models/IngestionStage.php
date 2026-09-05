<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IngestionStage extends Model
{
    protected $table = 'ingestion_stages';

    protected $fillable = [
        'ingestion_job_id',
        'stage_number',
        'stage_key',
        'status',
        'items_total',
        'items_succeeded',
        'items_failed',
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

    public function job(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(IngestionJob::class, 'ingestion_job_id');
    }
}
