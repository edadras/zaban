<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A seat in the room, and what the coach has allowed the person in it to do.
 *
 * The permissions are this system's record rather than a mirror of the media
 * server's. A learner who reconnects must come back muted if that is how the
 * coach left them, and the roll has to be readable when the media server is
 * not.
 */
class ClassParticipant extends Model
{
    use HasFactory;

    protected $fillable = [
        'class_session_id', 'user_id', 'role', 'can_publish_audio', 'can_publish_video',
        'is_present', 'hand_raised_at', 'first_joined_at', 'last_joined_at', 'left_at',
        'seconds_present',
    ];

    protected function casts(): array
    {
        return [
            'can_publish_audio' => 'boolean',
            'can_publish_video' => 'boolean',
            'is_present' => 'boolean',
            'hand_raised_at' => 'datetime',
            'first_joined_at' => 'datetime',
            'last_joined_at' => 'datetime',
            'left_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(ClassSession::class, 'class_session_id');
    }
}
