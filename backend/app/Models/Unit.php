<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Unit extends Model
{
    protected $table = 'units';

    protected $fillable = [
        'module_id',
        'topic_id',
        'title',
        'description',
        'cefr_level_id',
        'position',
        'estimated_minutes',
    ];

    public function module(): BelongsTo
    {
        return $this->belongsTo(Module::class);
    }

    public function topic(): BelongsTo
    {
        return $this->belongsTo(Topic::class);
    }

    public function lessons(): HasMany
    {
        return $this->hasMany(Lesson::class);
    }

    public function cefrLevel(): BelongsTo
    {
        return $this->belongsTo(CefrLevel::class, 'cefr_level_id');
    }

    public function audioMappings(): MorphMany
    {
        return $this->morphMany(AudioMapping::class, 'mappable');
    }
}
