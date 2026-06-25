<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Quote extends Model
{
    protected $fillable = [
        'company_id',
        'job_id',
        'customer_id',
        'quote_number',
        'scope_of_work',
        'contractor_base_price',
        'customer_price_before_gst',
        'hsop_markup',
        'gst_enabled',
        'subtotal',
        'gst_rate',
        'gst',
        'customer_total',
        'status',
        'pdf_ref',
        'internal_notes',
        'customer_notes',
        'rejection_reason',
        'customer_token',
        'sent_at',
        'viewed_at',
        'accepted_at',
    ];

    protected function casts(): array
    {
        return [
            'contractor_base_price' => 'decimal:2',
            'customer_price_before_gst' => 'decimal:2',
            'hsop_markup' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'gst_rate' => 'decimal:2',
            'gst' => 'decimal:2',
            'customer_total' => 'decimal:2',
            'gst_enabled' => 'boolean',
            'sent_at' => 'datetime',
            'viewed_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(QuoteItem::class)->orderBy('sort_order');
    }
}
