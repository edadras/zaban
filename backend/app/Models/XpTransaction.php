<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class XpTransaction extends Model
{
    protected $table = 'xp_transactions';

    protected $fillable = [
        'user_id',
        'amount',
        'reason',
        'source_type',
        'source_id',
    ];

    public function source(): \Illuminate\Database\Eloquent\Relations\MorphTo
    {
        return $this->morphTo();
    }
}
