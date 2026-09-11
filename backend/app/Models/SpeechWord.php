<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SpeechWord extends Model
{
    protected $table = 'speech_words';

    protected $fillable = [
        'speech_attempt_id',
        'position',
        'expected_word',
        'spoken_word',
        'start_ms',
        'end_ms',
        'confidence',
        'accuracy_score',
        'outcome',
        'stress_correct',
    ];

    protected function casts(): array
    {
        return [
            'confidence' => 'float',
            'accuracy_score' => 'float',
            'stress_correct' => 'boolean',
        ];
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(SpeechAttempt::class, 'speech_attempt_id');
    }

    public function phonemes(): HasMany
    {
        return $this->hasMany(SpeechPhoneme::class);
    }
}
