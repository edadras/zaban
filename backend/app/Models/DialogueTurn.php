<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DialogueTurn extends Model
{
    protected $table = 'dialogue_turns';

    protected $fillable = [
        'dialogue_id',
        'character_id',
        'position',
        'text',
        'translation',
        'audio_media_asset_id',
        'audio_start_ms',
        'audio_end_ms',
        'is_learner_turn',
    ];

    protected function casts(): array
    {
        return [
            'is_learner_turn' => 'boolean',
        ];
    }

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }

    public function dialogue(): BelongsTo
    {
        return $this->belongsTo(Dialogue::class);
    }
}
