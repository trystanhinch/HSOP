<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PricingRule extends Model
{
    protected $fillable = [
        'brand_id',
        'company_source_id',
        'service_category',
        'rule_type',
        'base_rate',
        'size_tiers',
        'complexity_modifiers',
        'min_price',
        'max_price',
        'currency',
        'status',
        'is_placeholder',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'base_rate' => 'float',
            'size_tiers' => 'array',
            'complexity_modifiers' => 'array',
            'min_price' => 'float',
            'max_price' => 'float',
            'is_placeholder' => 'boolean',
        ];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function companySource(): BelongsTo
    {
        return $this->belongsTo(CompanySource::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
