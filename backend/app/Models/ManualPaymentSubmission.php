<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ManualPaymentSubmission extends Model
{
    protected $table = 'manual_payment_submissions';

    public const STATUS_PENDING_TRANSFER = 'pending_transfer';

    public const STATUS_PENDING_REVIEW = 'pending_review';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'user_id',
        'plan_id',
        'plan_price_id',
        'payment_attempt_id',
        'status',
        'amount_try',
        'amount_irr',
        'fx_rate',
        'payer_name',
        'receipt_path',
        'receipt_original_name',
        'admin_note',
        'reviewed_by',
        'reviewed_at',
        'receipt_uploaded_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'amount_try' => 'integer',
            'amount_irr' => 'integer',
            'fx_rate' => 'float',
            'metadata' => 'array',
            'reviewed_at' => 'datetime',
            'receipt_uploaded_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function planPrice(): BelongsTo
    {
        return $this->belongsTo(PlanPrice::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function paymentAttempt(): BelongsTo
    {
        return $this->belongsTo(PaymentAttempt::class);
    }
}
