<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Character extends Model
{
    protected $table = 'characters';

    protected $fillable = [
        'slug',
        'name',
        'persona',
        'accent',
        'voice_id',
        'avatar_media_asset_id',
        'appearance_prompt',
    ];
}
