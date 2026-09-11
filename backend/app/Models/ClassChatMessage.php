<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One line in the live classroom chat. */
class ClassChatMessage extends Model
{
    protected $fillable = [
        'class_session_id',
        'user_id',
        'body',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(ClassSession::class, 'class_session_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
