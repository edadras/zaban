<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WordFamily extends Model
{
    protected $table = 'word_families';

    protected $fillable = [
        'language_id',
        'stem',
    ];
}
