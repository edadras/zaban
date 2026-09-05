<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One planned image: what to render, how, and what it attaches to once made.
 *
 * @see database/migrations/2025_01_02_000300_create_media_briefs_table.php
 */
class MediaBrief extends Model
{
    public const KIND_LESSON_SCENE = 'lesson_scene';

    public const KIND_VOCABULARY_CARD = 'vocabulary_card';

    public const KIND_CHARACTER_PORTRAIT = 'character_portrait';

    public const KIND_DIALOGUE_VIDEO = 'dialogue_video';

    public const KIND_LESSON_VIDEO = 'lesson_video';

    public const STATUS_PENDING = 'pending';

    public const STATUS_GENERATING = 'generating';

    public const STATUS_GENERATED = 'generated';

    public const STATUS_IMPORTED = 'imported';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    protected $fillable = [
        'kind', 'subject_type', 'subject_id', 'model', 'prompt', 'negative',
        'aspect_ratio', 'resolution', 'duration_seconds', 'source_brief_id',
        'priority', 'status', 'skip_reason',
        'request_hash', 'external_job_id', 'result_url', 'media_asset_id',
        'error', 'attempts', 'generated_at',
    ];

    protected $casts = [
        'priority' => 'integer',
        'attempts' => 'integer',
        'duration_seconds' => 'integer',
        'generated_at' => 'datetime',
    ];

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function mediaAsset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class);
    }

    /**
     * The request identity: change any of these and it is a different image, so
     * the brief must be re-rendered. Deliberately excludes status and results.
     */
    public static function hashFor(string $model, string $prompt, string $aspect, string $resolution): string
    {
        return hash('sha256', implode('|', [$model, $prompt, $aspect, $resolution]));
    }

    /**
     * Work still to be sent to a provider, in the order it should be sent.
     *
     * A brief that animates a still is held back until that still exists: sent
     * early it would have nothing to seed from and would generate a different
     * room, with different people, from the lesson it belongs to.
     */
    public function scopeRenderable($query)
    {
        return $query->whereIn('status', [self::STATUS_PENDING, self::STATUS_FAILED])
            ->where(fn ($q) => $q->whereNull('source_brief_id')
                ->orWhereIn('source_brief_id', self::query()
                    ->where('status', self::STATUS_IMPORTED)
                    ->select('id')))
            ->orderBy('priority')
            ->orderBy('id');
    }

    /** Planned, but waiting on the still it animates. */
    public function scopeBlocked($query)
    {
        return $query->whereIn('status', [self::STATUS_PENDING, self::STATUS_FAILED])
            ->whereNotNull('source_brief_id')
            ->whereNotIn('source_brief_id', self::query()
                ->where('status', self::STATUS_IMPORTED)
                ->select('id'));
    }

    public function sourceBrief(): BelongsTo
    {
        return $this->belongsTo(self::class, 'source_brief_id');
    }
}
