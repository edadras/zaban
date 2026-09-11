<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Course extends Model
{
    use SoftDeletes;

    protected $table = 'courses';

    protected $fillable = [
        'language_id',
        'from_cefr_level_id',
        'to_cefr_level_id',
        'slug',
        'title',
        'description',
        'cover_path',
        'track',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(CourseVersion::class);
    }

    public function fromLevel(): BelongsTo
    {
        return $this->belongsTo(CefrLevel::class, 'from_cefr_level_id');
    }

    public function toLevel(): BelongsTo
    {
        return $this->belongsTo(CefrLevel::class, 'to_cefr_level_id');
    }
}
