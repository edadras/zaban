<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PartOfSpeech extends Model
{
    protected $table = 'parts_of_speech';

    protected $fillable = [
        'code',
        'name',
        'abbreviation',
    ];
}
