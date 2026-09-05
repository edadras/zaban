<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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

    public function item(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(VocabularyItem::class, 'vocabulary_item_id');
    }

    public function definitions(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Definition::class);
    }

    public function translations(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Translation::class);
    }

    public function examples(): \Illuminate\Database\Eloquent\Relations\MorphMany
    {
        return $this->morphMany(Example::class, 'exemplifiable');
    }

    public function concept(): \Illuminate\Database\Eloquent\Relations\MorphOne
    {
        return $this->morphOne(Concept::class, 'conceptable');
    }
}
