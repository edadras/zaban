<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Definition extends Model
{
    protected $table = 'definitions';

    protected $fillable = [
        'vocabulary_sense_id',
        'language_id',
        'cefr_level_id',
        'text',
        'generation_method',
    ];

    public function sense(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(VocabularySense::class, 'vocabulary_sense_id');
    }
}
