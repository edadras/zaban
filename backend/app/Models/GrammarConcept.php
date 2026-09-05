<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class GrammarConcept extends Model
{
    use SoftDeletes;

    protected $table = 'grammar_concepts';

    protected $fillable = [
        'language_id',
        'slug',
        'title',
        'summary',
        'cefr_level_id',
        'category',
    ];

    public function rules(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(GrammarRule::class);
    }
}
