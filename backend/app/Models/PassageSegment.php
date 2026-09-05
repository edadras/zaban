<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PassageSegment extends Model
{
    protected $table = 'passage_segments';

    protected $fillable = [
        'passage_id',
        'position',
        'text',
        'audio_start_ms',
        'audio_end_ms',
    ];
}
