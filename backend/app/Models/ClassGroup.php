<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A class: one coach, a roll of learners, and a repeating time. */
class ClassGroup extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'school_id', 'coach_id', 'title', 'description', 'cefr_level_id',
        'course_version_id', 'capacity', 'timezone', 'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function coach(): BelongsTo
    {
        return $this->belongsTo(User::class, 'coach_id');
    }

    public function level(): BelongsTo
    {
        return $this->belongsTo(CefrLevel::class, 'cefr_level_id');
    }

    public function students(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'class_group_students')
            ->withPivot(['status', 'enrolled_at', 'withdrawn_at'])
            ->wherePivot('status', 'enrolled')
            ->withTimestamps();
    }

    public function rules(): HasMany
    {
        return $this->hasMany(ClassScheduleRule::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(ClassSession::class);
    }
}
