<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class VocabularySense extends Model
{
    protected $table = 'vocabulary_senses';

    protected $fillable = [
        'vocabulary_item_id',
        'sense_number',
        'part_of_speech_id',
        'cefr_level_id',
        'topic_id',
        'register',
        'domain',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(VocabularyItem::class, 'vocabulary_item_id');
    }

    public function definitions(): HasMany
    {
        return $this->hasMany(Definition::class);
    }

    public function translations(): HasMany
    {
        return $this->hasMany(Translation::class);
    }

    public function examples(): MorphMany
    {
        return $this->morphMany(Example::class, 'exemplifiable');
    }

    public function concept(): MorphOne
    {
        return $this->morphOne(Concept::class, 'conceptable');
    }
}
