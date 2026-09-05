<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CouponRedemption extends Model
{
    protected $table = 'coupon_redemptions';

    protected $fillable = [
        'coupon_id',
        'user_id',
        'subscription_id',
        'redeemed_at',
    ];

    protected function casts(): array
    {
        return [
            'redeemed_at' => 'datetime',
        ];
    }
}
