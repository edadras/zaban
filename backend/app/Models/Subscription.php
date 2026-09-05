<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Subscription extends Model
{
    protected $table = 'subscriptions';

    protected $fillable = [
        'user_id',
        'plan_id',
        'plan_price_id',
        'coupon_id',
        'gateway',
        'gateway_subscription_id',
        'gateway_customer_id',
        'status',
        'trial_ends_at',
        'current_period_start',
        'current_period_end',
        'cancel_at',
        'canceled_at',
        'ends_at',
        'cancel_at_period_end',
    ];

    protected function casts(): array
    {
        return [
            'trial_ends_at' => 'datetime',
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'cancel_at' => 'datetime',
            'canceled_at' => 'datetime',
            'ends_at' => 'datetime',
            'cancel_at_period_end' => 'boolean',
        ];
    }

    public function plan(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
