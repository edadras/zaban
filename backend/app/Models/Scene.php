<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Scene extends Model
{
    use SoftDeletes;

    protected $table = 'scenes';

    protected $fillable = [
        'slug',
        'conversation_scenario_id',
        'lesson_id',
        'language_id',
        'cefr_level_id',
        'title',
        'title_fa',
        'situation',
        'situation_fa',
        'environment',
        'light',
        'cast',
        'props',
        'vocabulary',
        'objectives',
        'estimated_seconds',
        'status',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'cast' => 'array',
            'props' => 'array',
            'vocabulary' => 'array',
            'objectives' => 'array',
            'light' => 'float',
            'published_at' => 'datetime',
        ];
    }

    public function beats(): HasMany
    {
        return $this->hasMany(SceneBeat::class)->orderBy('position');
    }

    public function scenario(): BelongsTo
    {
        return $this->belongsTo(ConversationScenario::class, 'conversation_scenario_id');
    }

    public function cefrLevel(): BelongsTo
    {
        return $this->belongsTo(CefrLevel::class, 'cefr_level_id');
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    /** The roles a learner can take, in the order the scene lists them. */
    public function roles(): array
    {
        return collect($this->cast ?? [])->pluck('role')->filter()->values()->all();
    }

    public function castFor(string $role): ?array
    {
        return collect($this->cast ?? [])->firstWhere('role', $role);
    }

    public function isPublished(): bool
    {
        return $this->status === 'published';
    }
}
