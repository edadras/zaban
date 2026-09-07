<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SpeechCoachSession extends Model
{
    protected $table = 'speech_coach_sessions';

    protected $fillable = [
        'user_id',
        'status',
        'turn_count',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'completed_at' => 'datetime',
        ];
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SpeechCoachMessage::class)->orderBy('position');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
