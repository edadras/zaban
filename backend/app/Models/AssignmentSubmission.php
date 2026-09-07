<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/** One learner's answer to one piece of homework. */
class AssignmentSubmission extends Model
{
    public const ASSIGNED = 'assigned';

    public const DRAFT = 'draft';

    public const SUBMITTED = 'submitted';

    public const MARKING = 'marking';

    public const MARKED = 'marked';

    /** Marked *and* given back: the only state a learner sees a score in. */
    public const RETURNED = 'returned';

    protected $fillable = [
        'assignment_id', 'user_id', 'status', 'body',
        'writing_attempt_id', 'speech_attempt_id',
        'submitted_at', 'is_late',
        'ai_score', 'ai_feedback', 'ai_model', 'ai_marked_at', 'ai_error',
        'score', 'feedback', 'marked_by', 'marked_at', 'returned_at',
    ];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'marked_at' => 'datetime',
            'returned_at' => 'datetime',
            'ai_marked_at' => 'datetime',
            'is_late' => 'boolean',
            'ai_feedback' => 'array',
            'ai_score' => 'float',
            'score' => 'float',
        ];
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class);
    }

    public function learner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function marker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'marked_by');
    }

    public function responses(): HasMany
    {
        return $this->hasMany(AssignmentResponse::class);
    }

    public function writingAttempt(): BelongsTo
    {
        return $this->belongsTo(WritingAttempt::class, 'writing_attempt_id');
    }

    public function speechAttempt(): BelongsTo
    {
        return $this->belongsTo(SpeechAttempt::class, 'speech_attempt_id');
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(ClassAttachment::class, 'attachable')->orderBy('position');
    }

    public function isHandedIn(): bool
    {
        return in_array($this->status, [
            self::SUBMITTED, self::MARKING, self::MARKED, self::RETURNED,
        ], true);
    }

    /** Whether the learner may see a score yet. */
    public function isReturned(): bool
    {
        return $this->status === self::RETURNED;
    }
}
