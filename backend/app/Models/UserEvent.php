<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserEvent extends Model
{
    protected $table = 'user_events';

    protected $fillable = [
        'user_id',
        'name',
        'category',
        'subject_type',
        'subject_id',
        'properties',
        'platform',
        'app_version',
        'session_uuid',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'properties' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public function subject(): \Illuminate\Database\Eloquent\Relations\MorphTo
    {
        return $this->morphTo();
    }
}
