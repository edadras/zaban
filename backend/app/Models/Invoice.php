<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    protected $table = 'invoices';

    protected $fillable = [
        'user_id',
        'subscription_id',
        'subscription_transaction_id',
        'number',
        'status',
        'subtotal',
        'discount_total',
        'tax_total',
        'total',
        'currency',
        'billing_details',
        'pdf_path',
        'issued_at',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'billing_details' => 'array',
            'issued_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }
}
