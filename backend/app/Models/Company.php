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
        'slug',
        'service_type',
        'email',
        'phone',
        'address',
        'gst_number',
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
