<?php

namespace App\Models;

use App\Models\Concerns\HasTestData;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinancialLedgerEntry extends Model
{
    use HasTestData;

    public const TYPE_INVOICE_ISSUED = 'invoice_issued';
    public const TYPE_PAYMENT_RECEIVED = 'payment_received';
    public const TYPE_PAYMENT_PARTIAL = 'payment_partial';
    public const TYPE_REFUND = 'refund';
    public const TYPE_DISPUTE = 'dispute';
    public const TYPE_PAYOUT_HELD = 'payout_held';
    public const TYPE_PAYOUT_APPROVED = 'payout_approved';
    public const TYPE_PAYOUT_PAID = 'payout_paid';
    public const TYPE_PAYOUT_FAILED = 'payout_failed';
    public const TYPE_PAYOUT_REVERSED = 'payout_reversed';
    public const TYPE_GST_COLLECTED = 'gst_collected';
    public const TYPE_STRIPE_FEE = 'stripe_fee';

    protected $fillable = [
        'entry_type',
        'direction',
        'amount',
        'gst_amount',
        'currency',
        'job_id',
        'invoice_id',
        'payment_id',
        'payout_id',
        'quote_id',
        'actor_user_id',
        'reference',
        'meta',
        'is_test_data',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'gst_amount' => 'decimal:2',
            'meta' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function payout(): BelongsTo
    {
        return $this->belongsTo(Payout::class);
    }
}
