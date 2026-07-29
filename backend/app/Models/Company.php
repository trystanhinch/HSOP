<?php

namespace App\Models;

use App\Models\Concerns\HasTestData;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Company extends Model
{
    use HasTestData;

    protected $fillable = [
        'name',
        'legal_name',
        'operating_name',
        'slug',
        'service_type',
        'email',
        'phone',
        'address',
        'remittance_address',
        'province',
        'timezone',
        'currency',
        'gst_number',
        'gst_verification_status',
        'invoice_prefix',
        'public_contact_email',
        'public_contact_phone',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    public function jobs(): HasMany
    {
        return $this->hasMany(Job::class);
    }
}
