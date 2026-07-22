<?php

namespace App\Models;

use App\Casts\DateOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    protected $appends = ['is_overdue', 'gst_amount', 'balance_due', 'total'];

    protected $fillable = [
        'company_id',
        'company_source_id',
        'source_company',
        'job_id',
        'quote_id',
        'customer_id',
        'invoice_number',
        'scope_of_work',
        'notes',
        'subtotal',
        'gst_rate',
        'amount',
        'gst',
        'balance',
        'amount_paid',
        'status',
        'due_date',
        'sent_at',
        'payment_date',
        'payment_method',
        'stripe_transaction_id',
        'stripe_checkout_session_id',
        'stripe_payment_intent_id',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'gst_rate' => 'decimal:2',
            'amount' => 'decimal:2',
            'gst' => 'decimal:2',
            'balance' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'due_date' => DateOnly::class,
            'payment_date' => DateOnly::class,
            'sent_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function companySource(): BelongsTo
    {
        return $this->belongsTo(CompanySource::class);
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class);
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function getIsOverdueAttribute(): bool
    {
        return $this->status !== 'paid'
            && $this->due_date
            && now()->startOfDay()->gt($this->due_date);
    }

    public function getGstAmountAttribute(): float
    {
        return (float) $this->gst;
    }

    public function getBalanceDueAttribute(): float
    {
        return (float) $this->balance;
    }

    public function getTotalAttribute(): float
    {
        return (float) $this->amount;
    }
}
