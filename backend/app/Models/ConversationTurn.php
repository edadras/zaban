<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConversationTurn extends Model
{
    protected $table = 'conversation_turns';

    protected $fillable = [
        'conversation_session_id',
        'position',
        'speaker',
        'text',
        'speech_attempt_id',
        'audio_media_asset_id',
        'observed_errors',
        'blocked_communication',
    ];

    protected function casts(): array
    {
        return [
            'observed_errors' => 'array',
            'blocked_communication' => 'boolean',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(ConversationSession::class, 'conversation_session_id');
    }
}
