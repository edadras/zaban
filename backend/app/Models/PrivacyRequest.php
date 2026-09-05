<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PrivacyRequest extends Model
{
    protected $table = 'privacy_requests';

    protected $fillable = [
        'user_id',
        'type',
        'status',
        'export_path',
        'expires_at',
        'completed_at',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
