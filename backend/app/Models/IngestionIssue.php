<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IngestionIssue extends Model
{
    protected $table = 'ingestion_issues';

    protected $fillable = [
        'ingestion_job_id',
        'ingestion_stage_id',
        'severity',
        'code',
        'message',
        'subject_type',
        'subject_id',
        'context',
        'resolved',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'resolved' => 'boolean',
        ];
    }
}
