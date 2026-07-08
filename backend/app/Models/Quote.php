<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
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

    public static function generateNextQuoteNumber(): string
    {
        return DB::transaction(fn () => static::generateNextQuoteNumberWithinTransaction());
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function createWithUniqueQuoteNumber(array $attributes): self
    {
        $attempts = 0;

        do {
            try {
                return DB::transaction(function () use ($attributes) {
                    $quoteNumber = static::generateNextQuoteNumberWithinTransaction();

                    return static::create([
                        ...$attributes,
                        'quote_number' => $quoteNumber,
                    ]);
                });
            } catch (QueryException $e) {
                if ($attempts >= 9 || ! static::isDuplicateQuoteNumberException($e)) {
                    throw $e;
                }
                $attempts++;
            }
        } while ($attempts < 10);

        throw new \RuntimeException('Could not generate unique quote number after multiple attempts.');
    }

    protected static function generateNextQuoteNumberWithinTransaction(): string
    {
        $attempts = 0;

        do {
            $lastQuote = static::orderByRaw('CAST(SUBSTRING(quote_number, 4) AS UNSIGNED) DESC')
                ->lockForUpdate()
                ->first();
            $nextNumber = $lastQuote
                ? (int) substr($lastQuote->quote_number, 3) + 1
                : 1;
            $quoteNumber = 'QT-'.str_pad((string) $nextNumber, 4, '0', STR_PAD_LEFT);
            $attempts++;
        } while (static::where('quote_number', $quoteNumber)->exists() && $attempts < 10);

        return $quoteNumber;
    }

    public static function isDuplicateQuoteNumberException(QueryException $e): bool
    {
        $message = $e->getMessage();

        return str_contains($message, 'quotes_quote_number_unique')
            || str_contains($message, 'Duplicate entry')
            || str_contains($message, '1062');
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
