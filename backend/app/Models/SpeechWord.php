<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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

    public function attempt(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(SpeechAttempt::class, 'speech_attempt_id');
    }

    public function phonemes(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(SpeechPhoneme::class);
    }
}
