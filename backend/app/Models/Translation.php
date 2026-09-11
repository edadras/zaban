<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Translation extends Model
{
    protected $table = 'translations';

    protected $fillable = [
        'vocabulary_sense_id',
        'language_id',
        'text',
        'is_primary',
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
        ];
    }

    public function sense(): BelongsTo
    {
        return $this->belongsTo(VocabularySense::class, 'vocabulary_sense_id');
    }
}
