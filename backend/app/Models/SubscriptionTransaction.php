<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubscriptionTransaction extends Model
{
    protected $table = 'subscription_transactions';

    protected $fillable = [
        'subscription_id',
        'user_id',
        'gateway',
        'gateway_transaction_id',
        'type',
        'status',
        'amount',
        'currency',
        'refunded_amount',
        'failure_reason',
        'gateway_payload',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'gateway_payload' => 'array',
            'processed_at' => 'datetime',
        ];
    }
}
