<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
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

    public function language(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Language::class);
    }

    public function senses(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(VocabularySense::class);
    }

    public function forms(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(WordForm::class);
    }

    public function cefrLevel(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(CefrLevel::class, 'cefr_level_id');
    }

    public function partOfSpeech(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(PartOfSpeech::class, 'primary_part_of_speech_id');
    }
}
