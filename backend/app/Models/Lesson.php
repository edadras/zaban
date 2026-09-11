<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
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

    /**
     * Only what a learner is allowed to see.
     *
     * Everything imports as a draft, because the pipeline reads scanned pages
     * and some of what it produces is a heading the scanner invented. Staff see
     * drafts so they can look before releasing; nobody else does, or the switch
     * in the admin screen would decide nothing.
     */
    public function scopeVisibleTo($query, ?User $user)
    {
        return $query->when(
            ! ($user?->isAdmin() ?? false),
            fn ($q) => $q->where('lessons.status', 'published'),
        );
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function blocks(): HasMany
    {
        return $this->hasMany(LessonBlock::class);
    }

    public function exercises(): HasMany
    {
        return $this->hasMany(Exercise::class);
    }

    public function concepts(): BelongsToMany
    {
        return $this->belongsToMany(Concept::class, 'lesson_concept');
    }

    public function review(): MorphOne
    {
        return $this->morphOne(ContentReview::class, 'reviewable');
    }

    public function audioMappings(): MorphMany
    {
        return $this->morphMany(AudioMapping::class, 'mappable');
    }
}
