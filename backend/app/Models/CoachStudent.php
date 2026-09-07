<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The school's decision that this coach looks after this learner. */
class CoachStudent extends Model
{
    use HasFactory;

    protected $fillable = [
        'school_id', 'coach_id', 'student_id', 'assigned_by', 'note', 'assigned_at', 'ended_at',
    ];

    protected function casts(): array
    {
        return ['assigned_at' => 'datetime', 'ended_at' => 'datetime'];
    }

    public function coach(): BelongsTo
    {
        return $this->belongsTo(User::class, 'coach_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }
}
