<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Exercise extends Model
{
    use SoftDeletes;

    /**
     * Statuses an item may be shown to a learner under.
     *
     * `approved` is what the deterministic builder writes; `published` is what
     * the admin review flow writes. Anything else - `draft` above all - is
     * material we hold but cannot serve: the books' own exercise sections
     * import as one row per printed instruction ("Answer the questions."),
     * because their numbered parts belong to the page rather than the database,
     * and a learner handed one has nothing to answer.
     */
    public const SERVABLE_STATUSES = ['approved', 'published'];

    protected $table = 'exercises';

    /** @param  Builder<self>  $query */
    public function scopeServable($query)
    {
        return $query->whereIn('exercises.status', self::SERVABLE_STATUSES);
    }

    protected $fillable = [
        'exercise_template_id',
        'language_id',
        'lesson_id',
        'skill_id',
        'subskill_id',
        'cefr_level_id',
        'stem',
        'instructions',
        'payload',
        'difficulty',
        'discrimination',
        'guessing',
        'attempt_count',
        'correct_count',
        'avg_response_ms',
        'media_asset_id',
        'audio_media_asset_id',
        'passage_id',
        'dialogue_id',
        'status',
        'validation_score',
        'is_placement_eligible',
        'is_exam_eligible',
        'generation_method',
        'copyright_status',
        'source_document_id',
        'source_page',
        'source_reference',
        'ai_generation_id',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'difficulty' => 'float',
            'discrimination' => 'float',
            'guessing' => 'float',
            'validation_score' => 'float',
            'is_placement_eligible' => 'boolean',
            'is_exam_eligible' => 'boolean',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ExerciseTemplate::class, 'exercise_template_id');
    }

    public function skill(): BelongsTo
    {
        return $this->belongsTo(Skill::class);
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function options(): HasMany
    {
        return $this->hasMany(ExerciseOption::class);
    }

    public function hints(): HasMany
    {
        return $this->hasMany(ExerciseHint::class)->orderBy('level');
    }

    public function explanations(): HasMany
    {
        return $this->hasMany(ExerciseExplanation::class);
    }

    public function answers(): HasMany
    {
        return $this->hasMany(ExerciseAnswer::class);
    }

    public function concepts(): BelongsToMany
    {
        return $this->belongsToMany(Concept::class, 'exercise_concepts');
    }

    public function review(): MorphOne
    {
        return $this->morphOne(ContentReview::class, 'reviewable');
    }
}
