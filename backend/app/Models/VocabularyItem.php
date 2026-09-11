<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class VocabularyItem extends Model
{
    use SoftDeletes;

    protected $table = 'vocabulary_items';

    protected $fillable = [
        'language_id',
        'headword',
        'normalised',
        'primary_part_of_speech_id',
        'cefr_level_id',
        'frequency_rank',
        'ipa',
        'word_family_id',
    ];

    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class);
    }

    public function senses(): HasMany
    {
        return $this->hasMany(VocabularySense::class);
    }

    public function forms(): HasMany
    {
        return $this->hasMany(WordForm::class);
    }

    public function cefrLevel(): BelongsTo
    {
        return $this->belongsTo(CefrLevel::class, 'cefr_level_id');
    }

    public function partOfSpeech(): BelongsTo
    {
        return $this->belongsTo(PartOfSpeech::class, 'primary_part_of_speech_id');
    }
}
