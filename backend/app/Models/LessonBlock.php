<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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

    public function lesson(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function exercise(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Exercise::class);
    }

    public function mediaAsset(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'media_asset_id');
    }
}
