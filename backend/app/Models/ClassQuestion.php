<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A question put to the room, or to the few learners the coach named. */
class ClassQuestion extends Model
{
    use HasFactory;

    public const OPEN = 'open';
    public const POLL = 'poll';
    public const EXERCISE = 'exercise';

    protected $fillable = [
        'class_session_id', 'asked_by', 'exercise_id', 'class_material_id', 'kind',
        'prompt', 'options', 'correct_options', 'addressed_user_ids', 'opened_at', 'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'correct_options' => 'array',
            'addressed_user_ids' => 'array',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function answers(): HasMany
    {
        return $this->hasMany(ClassAnswer::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(ClassSession::class, 'class_session_id');
    }

    /** Null means the whole room; a list means the learners the coach picked. */
    public function addresses(int $userId): bool
    {
        return $this->addressed_user_ids === null
            || in_array($userId, $this->addressed_user_ids, true);
    }
}
