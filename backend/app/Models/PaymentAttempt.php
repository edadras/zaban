<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentAttempt extends Model
{
    protected $table = 'payment_attempts';

    protected $fillable = [
        'user_id',
        'plan_id',
        'plan_price_id',
        'coupon_id',
        'gateway',
        'idempotency_key',
        'status',
        'amount',
        'currency',
        'gateway_reference',
        'failure_reason',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }
}
