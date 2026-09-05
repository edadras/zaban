<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentWebhook extends Model
{
    protected $table = 'payment_webhooks';

    protected $fillable = [
        'gateway',
        'event_id',
        'event_type',
        'signature_verified',
        'status',
        'payload',
        'error',
        'attempts',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'signature_verified' => 'boolean',
            'processed_at' => 'datetime',
        ];
    }
}
