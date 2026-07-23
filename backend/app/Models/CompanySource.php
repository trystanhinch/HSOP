<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanySource extends Model
{
    protected $fillable = [
        'company_name',
        'domain',
        'service_categories',
        'google_review_url',
        'default_pm_id',
        'default_contractor_ids',
        'sender_identity',
        'lead_parsing_rule',
        'marketing_cost_monthly',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'service_categories' => 'array',
            'default_contractor_ids' => 'array',
            'marketing_cost_monthly' => 'decimal:2',
        ];
    }

    public function defaultPm(): BelongsTo
    {
        return $this->belongsTo(User::class, 'default_pm_id');
    }
}
