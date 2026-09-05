<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GrammarRule extends Model
{
    protected $table = 'grammar_rules';

    protected $fillable = [
        'grammar_concept_id',
        'title',
        'statement',
        'formula',
        'cefr_level_id',
        'position',
        'generation_method',
    ];
}
