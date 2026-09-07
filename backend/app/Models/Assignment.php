<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One piece of homework, set for one class.
 *
 * The kinds are not interchangeable: what a learner does, what comes back, and
 * whether a machine can mark it all follow from this one column.
 */
class Assignment extends Model
{
    use HasFactory;
    use SoftDeletes;

    /** A paragraph to write. Marked by the writing analyser. */
    public const WRITING = 'writing';

    /** A passage to read aloud. Marked by the speech analyser. */
    public const SPEAKING = 'speaking';

    /** Items from the course. Marked by arithmetic. */
    public const EXERCISES = 'exercises';

    /** A photograph or a file. Read, then marked like writing. */
    public const UPLOAD = 'upload';

    /** "Do your twenty minutes." Checked against what the app recorded. */
    public const PRACTICE = 'practice';

    /** Something to read or watch, and say you have done. */
    public const READING = 'reading';

    public const KINDS = [
        self::WRITING, self::SPEAKING, self::EXERCISES,
        self::UPLOAD, self::PRACTICE, self::READING,
    ];

    /** The kinds the assistant can take a first pass at. */
    public const MARKABLE = [self::WRITING, self::SPEAKING, self::EXERCISES, self::UPLOAD];

    protected $fillable = [
        'class_group_id', 'class_session_id', 'created_by', 'kind', 'title',
        'brief', 'lesson_id', 'cefr_level_id', 'due_at', 'published_at',
        'points', 'allow_late', 'auto_release', 'settings',
    ];

    protected function casts(): array
    {
        return [
            'due_at' => 'datetime',
            'published_at' => 'datetime',
            'allow_late' => 'boolean',
            'auto_release' => 'boolean',
            'settings' => 'array',
            'points' => 'integer',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(ClassGroup::class, 'class_group_id');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(ClassSession::class, 'class_session_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(AssignmentItem::class)->orderBy('position');
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(AssignmentSubmission::class);
    }

    public function isPublished(): bool
    {
        return $this->published_at !== null;
    }

    public function isOverdue(): bool
    {
        return $this->due_at !== null && $this->due_at->isPast();
    }

    /**
     * Whether work handed in now is still accepted.
     *
     * A due date a learner cannot miss is a deadline nobody believes; one that
     * slams shut at midnight loses work somebody did. So late work is taken
     * for a grace period, and marked late.
     */
    public function acceptsWorkNow(): bool
    {
        if (! $this->isPublished()) {
            return false;
        }
        if (! $this->isOverdue()) {
            return true;
        }
        if (! $this->allow_late) {
            return false;
        }

        return $this->due_at->addHours((int) config('classroom.homework.late_grace_hours', 72))->isFuture();
    }

    public function canBeMarkedByMachine(): bool
    {
        return in_array($this->kind, self::MARKABLE, true);
    }
}
