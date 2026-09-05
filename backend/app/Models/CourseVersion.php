<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CourseVersion extends Model
{
    protected $table = 'course_versions';

    protected $fillable = [
        'course_id',
        'version',
        'status',
        'changelog',
        'published_by',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
        ];
    }

    public function course(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function modules(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Module::class);
    }
}
