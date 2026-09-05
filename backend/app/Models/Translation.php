<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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

    public function sense(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(VocabularySense::class, 'vocabulary_sense_id');
    }
}
