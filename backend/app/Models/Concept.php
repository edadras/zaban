<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Concept extends Model
{
    protected $table = 'concepts';

    protected $fillable = [
        'conceptable_type',
        'conceptable_id',
        'language_id',
        'skill_id',
        'cefr_level_id',
        'label',
        'difficulty',
        'importance',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'difficulty' => 'float',
            'importance' => 'float',
            'is_active' => 'boolean',
        ];
    }

    public function conceptable(): \Illuminate\Database\Eloquent\Relations\MorphTo
    {
        return $this->morphTo();
    }

    public function skill(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Skill::class);
    }

    public function prerequisites(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(self::class, 'concept_prerequisites', 'concept_id', 'prerequisite_concept_id')
            ->withPivot(['strength', 'is_blocking']);
    }

    public function lessons(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Lesson::class, 'lesson_concept');
    }
}
