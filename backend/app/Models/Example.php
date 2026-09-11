<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Example extends Model
{
    protected $table = 'examples';

    protected $fillable = [
        'exemplifiable_type',
        'exemplifiable_id',
        'language_id',
        'cefr_level_id',
        'text',
        'translation',
        'media_asset_id',
        'generation_method',
        'copyright_status',
        'position',
    ];

    public function exemplifiable(): MorphTo
    {
        return $this->morphTo();
    }
}
