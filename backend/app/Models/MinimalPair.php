<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MinimalPair extends Model
{
    protected $table = 'minimal_pairs';

    protected $fillable = [
        'language_id',
        'phoneme_a_id',
        'phoneme_b_id',
        'item_a_id',
        'item_b_id',
        'cefr_level_id',
    ];
}
