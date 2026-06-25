<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Contractor extends Model
{
    protected $fillable = [
        'user_id',
        'legal_name',
        'operating_name',
        'contact_name',
        'phone',
        'email',
        'services',
        'cities',
        'wcb_status',
        'wcb_expiry_date',
        'wcb_file_url',
        'liability_insurance_status',
        'insurance_expiry_date',
        'insurance_file_url',
        'approval_status',
        'payment_info',
    ];

    protected function casts(): array
    {
        return [
            'services' => 'array',
            'cities' => 'array',
            'payment_info' => 'array',
            'wcb_expiry_date' => 'date',
            'insurance_expiry_date' => 'date',
        ];
    }

    public function documents()
    {
        return $this->hasMany(ContractorDocument::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
