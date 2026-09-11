<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SceneBeat extends Model
{
    protected $table = 'scene_beats';

    protected $fillable = [
        'scene_id',
        'position',
        'role',
        'text',
        'translation_fa',
        'camera',
        'animation',
        'gesture',
        'expression',
        'audio_media_asset_id',
        'audio_start_ms',
        'audio_end_ms',
        'audio_method',
        'audio_confidence',
        'audio_review_status',
        'interaction',
        'prompt',
        'prompt_fa',
        'choices',
        'accept',
        'hint',
        'hint_fa',
    ];

    protected function casts(): array
    {
        return [
            'choices' => 'array',
            'accept' => 'array',
            'audio_confidence' => 'float',
        ];
    }

    public function scene(): BelongsTo
    {
        return $this->belongsTo(Scene::class);
    }

    public function audio(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'audio_media_asset_id');
    }

    /** True when the learner has to produce this line rather than hear it. */
    public function isLearnerTurn(): bool
    {
        return $this->interaction !== 'watch';
    }

    /**
     * Everything that counts as having said this line.
     *
     * The written line always counts; `accept` adds the other natural ways of
     * saying it, because "Could I have the bill?" and "Can I get the bill?"
     * are the same answer and only one of them is printed.
     */
    public function acceptedAnswers(): array
    {
        $extra = array_values(array_filter(
            (array) ($this->accept ?? []),
            fn ($a) => is_string($a) && trim($a) !== '',
        ));

        return $this->interaction === 'choose'
            ? $extra
            : array_values(array_unique([$this->text, ...$extra]));
    }
}
