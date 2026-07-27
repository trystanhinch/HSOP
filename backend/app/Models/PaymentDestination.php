<?php

namespace App\Models;

use App\Models\Concerns\HasTestData;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentDestination extends Model
{
    use HasTestData;

    public const METHOD_STRIPE = 'stripe';
    public const METHOD_E_TRANSFER = 'e_transfer';

    public const TYPE_COMPANY_VERIFIED = 'company_verified';
    public const TYPE_CONTRACTOR = 'contractor';

    protected $fillable = [
        'brand_id',
        'payment_method',
        'destination_type',
        'destination_value',
        'is_verified',
        'needs_owner_review',
        'is_active',
        'contractor_email_override',
        'override_reason',
        'verified_by',
        'verified_at',
        'updated_by',
        'legacy_source_note',
        'meta',
        'is_test_data',
    ];

    protected function casts(): array
    {
        return [
            'is_verified' => 'boolean',
            'needs_owner_review' => 'boolean',
            'is_active' => 'boolean',
            'contractor_email_override' => 'boolean',
            'verified_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function isCustomerFacing(): bool
    {
        return $this->is_active
            && $this->is_verified
            && ! $this->needs_owner_review
            && $this->destination_type === self::TYPE_COMPANY_VERIFIED;
    }

    public function displayValue(): ?string
    {
        if ($this->payment_method === self::METHOD_STRIPE) {
            return $this->destination_value ?: 'platform';
        }

        return $this->destination_value;
    }
}
