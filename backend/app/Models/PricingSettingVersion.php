<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PricingSettingVersion extends Model
{
    protected $fillable = [
        'brand_id',
        'effective_date',
        'gst_rate',
        'markup_divisor',
        'split_contractor_pct',
        'split_pm_pct',
        'split_company_pct',
        'created_by',
        'previous_values',
        'change_reason',
    ];

    protected function casts(): array
    {
        return [
            'effective_date' => 'date',
            'gst_rate' => 'float',
            'markup_divisor' => 'float',
            'split_contractor_pct' => 'float',
            'split_pm_pct' => 'float',
            'split_company_pct' => 'float',
            'previous_values' => 'array',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }
}
