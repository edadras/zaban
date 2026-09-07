<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** An answer, or a comment on one. */
class ClassThreadReply extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'class_thread_id', 'author_id', 'parent_reply_id', 'body',
        'is_coach_answer', 'is_ai_answer', 'ai_endorsed', 'helpful_count',
        'hidden_at', 'hidden_by', 'hidden_reason',
    ];

    protected function casts(): array
    {
        return [
            'is_coach_answer' => 'boolean',
            'is_ai_answer' => 'boolean',
            'ai_endorsed' => 'boolean',
            'helpful_count' => 'integer',
            'hidden_at' => 'datetime',
        ];
    }

    public function thread(): BelongsTo
    {
        return $this->belongsTo(ClassThread::class, 'class_thread_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_reply_id');
    }

    public function votes(): HasMany
    {
        return $this->hasMany(ClassThreadVote::class);
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(ClassAttachment::class, 'attachable')->orderBy('position');
    }

    public function isHidden(): bool
    {
        return $this->hidden_at !== null;
    }
}
