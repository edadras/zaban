<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SpeechAttempt extends Model
{
    protected $table = 'speech_attempts';

    protected $fillable = [
        'user_id',
        'exercise_id',
        'production_prompt_id',
        'pronunciation_item_id',
        'learning_session_id',
        'media_asset_id',
        'audio_deleted',
        'audio_delete_after',
        'expected_text',
        'transcript',
        'duration_ms',
        'status',
        'error',
        'overall_score',
        'pronunciation_score',
        'fluency_score',
        'grammar_score',
        'vocabulary_score',
        'completeness_score',
        'speech_rate_wpm',
        'pause_count',
        'total_pause_ms',
        'filler_count',
        'feedback',
        'stt_provider',
        'aligner',
        'scored_at',
    ];

    protected function casts(): array
    {
        return [
            'feedback' => 'array',
            'audio_deleted' => 'boolean',
            'audio_delete_after' => 'datetime',
            'scored_at' => 'datetime',
            'overall_score' => 'float',
            'pronunciation_score' => 'float',
            'fluency_score' => 'float',
            'grammar_score' => 'float',
            'vocabulary_score' => 'float',
            'completeness_score' => 'float',
            'speech_rate_wpm' => 'float',
        ];
    }

    public function words(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(SpeechWord::class);
    }

    public function mediaAsset(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'media_asset_id');
    }

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
