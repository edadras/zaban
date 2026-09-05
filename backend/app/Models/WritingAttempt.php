<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One piece of writing a learner produced, typed or photographed off paper.
 *
 * @see database/migrations/2025_01_02_000600_create_writing_attempts_table.php
 */
class WritingAttempt extends Model
{
    public const SOURCE_TYPED = 'typed';

    public const SOURCE_PHOTO = 'photo';

    public const STATUS_PENDING = 'pending';

    public const STATUS_RECOGNISING = 'recognising';

    /** The vision model has read the page; the learner must confirm it. */
    public const STATUS_AWAITING_CONFIRMATION = 'awaiting_confirmation';

    public const STATUS_SCORING = 'scoring';

    public const STATUS_SCORED = 'scored';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'user_id', 'production_prompt_id', 'exercise_id', 'lesson_id',
        'learning_session_id', 'source', 'media_asset_id', 'recognised_text',
        'recognition_confidence', 'text_confirmed', 'text', 'word_count',
        'status', 'error', 'overall_score', 'task_achievement_score',
        'coherence_score', 'grammar_score', 'vocabulary_score',
        'mechanics_score', 'cefr_level_id', 'corrections', 'feedback',
        'analyser', 'scored_at',
    ];

    protected function casts(): array
    {
        return [
            'corrections' => 'array',
            'feedback' => 'array',
            'text_confirmed' => 'boolean',
            'recognition_confidence' => 'float',
            'scored_at' => 'datetime',
        ];
    }

    public function prompt(): BelongsTo
    {
        return $this->belongsTo(ProductionPrompt::class, 'production_prompt_id');
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'media_asset_id');
    }

    /**
     * Is this attempt's text something the learner has actually stood behind?
     *
     * Typed text is authoritative the moment it arrives. Recognised handwriting
     * is not: it is a machine's reading, and scoring it unconfirmed would mark
     * someone down for the OCR's mistakes.
     */
    public function textIsAuthoritative(): bool
    {
        return $this->source === self::SOURCE_TYPED || $this->text_confirmed;
    }
}
