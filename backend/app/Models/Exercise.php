<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Exercise extends Model
{
    use SoftDeletes;

    protected $table = 'exercises';

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

    public function template(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(ExerciseTemplate::class, 'exercise_template_id');
    }

    public function lesson(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function options(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ExerciseOption::class);
    }

    public function answers(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ExerciseAnswer::class);
    }

    public function concepts(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Concept::class, 'exercise_concepts');
    }

    public function review(): \Illuminate\Database\Eloquent\Relations\MorphOne
    {
        return $this->morphOne(ContentReview::class, 'reviewable');
    }
}
