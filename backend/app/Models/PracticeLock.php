<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The class reaching into the rest of the learner's day.
 *
 * While one of these is open the session composer stops choosing for itself and
 * draws only from the concepts named here, so the evening's practice is the
 * afternoon's lesson. It carries its own expiry: a coach who forgets to lift it
 * does not freeze someone's curriculum for good.
 */
class PracticeLock extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'class_session_id', 'created_by', 'concept_ids', 'lesson_ids',
        'note', 'starts_at', 'expires_at', 'released_at',
    ];

    protected function casts(): array
    {
        return [
            'concept_ids' => 'array',
            'lesson_ids' => 'array',
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('released_at')
            ->where('starts_at', '<=', now())
            ->where('expires_at', '>', now());
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(ClassSession::class, 'class_session_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The one in force for this learner, or null when they are free to roam. */
    public static function forUser(int $userId): ?self
    {
        return self::query()->active()->where('user_id', $userId)
            ->latest('starts_at')->first();
    }
}
