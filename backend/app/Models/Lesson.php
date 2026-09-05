<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Lesson extends Model
{
    use SoftDeletes;

    protected $table = 'lessons';

    protected $fillable = [
        'unit_id',
        'title',
        'summary',
        'cefr_level_id',
        'kind',
        'position',
        'estimated_minutes',
        'status',
        'source_document_id',
        'source_page',
        'source_section',
        'generation_method',
        'copyright_status',
    ];

    public function unit(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function blocks(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(LessonBlock::class);
    }

    public function exercises(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Exercise::class);
    }

    public function concepts(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Concept::class, 'lesson_concept');
    }

    public function review(): \Illuminate\Database\Eloquent\Relations\MorphOne
    {
        return $this->morphOne(ContentReview::class, 'reviewable');
    }

    public function audioMappings(): \Illuminate\Database\Eloquent\Relations\MorphMany
    {
        return $this->morphMany(AudioMapping::class, 'mappable');
    }
}
