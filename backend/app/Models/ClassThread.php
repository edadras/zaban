<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** One question, or one conversation, on a class's board. */
class ClassThread extends Model
{
    use HasFactory;
    use SoftDeletes;

    public const QUESTION = 'question';

    public const DISCUSSION = 'discussion';

    public const ANNOUNCEMENT = 'announcement';

    public const KINDS = [self::QUESTION, self::DISCUSSION, self::ANNOUNCEMENT];

    public const OPEN = 'open';

    public const RESOLVED = 'resolved';

    public const CLOSED = 'closed';

    protected $fillable = [
        'class_group_id', 'author_id', 'kind', 'title', 'body', 'status',
        'accepted_reply_id', 'pinned_at', 'locked_at', 'hidden_at', 'hidden_by',
        'hidden_reason', 'reply_count', 'view_count', 'last_activity_at',
    ];

    protected function casts(): array
    {
        return [
            'pinned_at' => 'datetime',
            'locked_at' => 'datetime',
            'hidden_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'reply_count' => 'integer',
            'view_count' => 'integer',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(ClassGroup::class, 'class_group_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(ClassThreadReply::class)->orderBy('created_at');
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(ClassAttachment::class, 'attachable')->orderBy('position');
    }

    public function reads(): HasMany
    {
        return $this->hasMany(ClassThreadRead::class);
    }

    public function isLocked(): bool
    {
        return $this->locked_at !== null || $this->status === self::CLOSED;
    }

    public function isHidden(): bool
    {
        return $this->hidden_at !== null;
    }
}
