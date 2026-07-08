<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;

class Quote extends Model
{
    protected static ?bool $hasLeadIdColumn = null;

    protected $fillable = [
        'lead_id',
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
        'contractor_pct',
        'pm_pct',
        'company_pct',
        'pm_amount',
        'company_amount',
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
            'contractor_pct' => 'decimal:2',
            'pm_pct' => 'decimal:2',
            'company_pct' => 'decimal:2',
            'pm_amount' => 'decimal:2',
            'company_amount' => 'decimal:2',
            'gst_enabled' => 'boolean',
            'sent_at' => 'datetime',
            'viewed_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    public static function hasLeadIdColumn(): bool
    {
        if (self::$hasLeadIdColumn === null) {
            self::$hasLeadIdColumn = Schema::hasColumn('quotes', 'lead_id');
        }

        return self::$hasLeadIdColumn;
    }

    /** Quote attached directly to a lead before a job exists (requires quotes.lead_id column). */
    public static function leadLevelFor(Lead $lead): ?self
    {
        if (! self::hasLeadIdColumn()) {
            return null;
        }

        return static::where('lead_id', $lead->id)->whereNull('job_id')->latest()->first();
    }

    /** Most relevant quote for a lead — lead-level first, then via job. */
    public static function forLead(Lead $lead): ?self
    {
        $leadQuote = self::leadLevelFor($lead);
        if ($leadQuote) {
            return $leadQuote;
        }

        return static::whereHas('job', fn ($q) => $q->where('lead_id', $lead->id))->latest()->first();
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
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
