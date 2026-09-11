<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LessonBlock extends Model
{
    protected $table = 'lesson_blocks';

    protected $fillable = [
        'lesson_id',
        'type',
        'position',
        'title',
        'instructions',
        'config',
        'exercise_id',
        'media_asset_id',
        'dialogue_id',
        'estimated_seconds',
        'is_optional',
    ];

    protected function casts(): array
    {
        return [
            'config' => 'array',
            'is_optional' => 'boolean',
        ];
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function exercise(): BelongsTo
    {
        return $this->belongsTo(Exercise::class);
    }

    public function mediaAsset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'media_asset_id');
    }
}
