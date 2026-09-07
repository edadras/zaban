<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SpeechCoachMessage extends Model
{
    protected $table = 'speech_coach_messages';

    protected $fillable = [
        'speech_coach_session_id',
        'position',
        'role',
        'text',
        'text_fa',
        'corrected_text',
        'coaching',
        'speech_attempt_id',
    ];

    protected function casts(): array
    {
        return [
            'coaching' => 'array',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(SpeechCoachSession::class, 'speech_coach_session_id');
    }
}
