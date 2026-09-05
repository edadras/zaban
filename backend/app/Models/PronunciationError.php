<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PronunciationError extends Model
{
    protected $table = 'pronunciation_errors';

    protected $fillable = [
        'user_id',
        'phoneme_id',
        'substituted_phoneme_id',
        'occurrence_count',
        'attempt_count',
        'error_rate',
        'recent_error_rate',
        'example_words',
        'first_seen_at',
        'last_seen_at',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'example_words' => 'array',
            'error_rate' => 'float',
            'recent_error_rate' => 'float',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function phoneme(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Phoneme::class);
    }
}
