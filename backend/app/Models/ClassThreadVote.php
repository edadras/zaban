<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One person saying an answer helped. */
class ClassThreadVote extends Model
{
    protected $fillable = ['class_thread_reply_id', 'user_id'];

    public function reply(): BelongsTo
    {
        return $this->belongsTo(ClassThreadReply::class, 'class_thread_reply_id');
    }
}
