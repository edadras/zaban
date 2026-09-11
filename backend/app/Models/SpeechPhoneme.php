<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SpeechPhoneme extends Model
{
    protected $table = 'speech_phonemes';

    protected $fillable = [
        'speech_word_id',
        'expected_phoneme_id',
        'actual_phoneme_id',
        'position',
        'start_ms',
        'end_ms',
        'accuracy_score',
        'is_error',
    ];

    protected function casts(): array
    {
        return [
            'accuracy_score' => 'float',
            'is_error' => 'boolean',
        ];
    }

    public function word(): BelongsTo
    {
        return $this->belongsTo(SpeechWord::class, 'speech_word_id');
    }

    public function expectedPhoneme(): BelongsTo
    {
        return $this->belongsTo(Phoneme::class, 'expected_phoneme_id');
    }
}
