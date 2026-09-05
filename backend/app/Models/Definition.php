<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Definition extends Model
{
    /**
     * How a definition that cannot be trusted is marked.
     *
     * The importer places a margin gloss by matching an anchor phrase from the
     * page, and where a page repeats that phrase the same note lands on several
     * headwords at once - "drink some coffee" arrived as the definition of
     * "drive". One of them may be right and nothing distinguishes it, so all of
     * them are marked rather than deleted: the text came out of the book and is
     * worth keeping for review, but it must not be shown to a learner and must
     * not be used to decide whether two words mean different things.
     */
    public const AMBIGUOUS = 'extracted_ambiguous';

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
