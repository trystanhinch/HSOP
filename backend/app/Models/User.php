<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'password',
        'role',
        'status',
        'sms_enabled',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'sms_enabled' => 'boolean',
        ];
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class, 'customer_id');
    }

    public function assignedLeads(): HasMany
    {
        return $this->hasMany(Lead::class, 'assigned_pm_id');
    }

    public function jobs(): HasMany
    {
        return $this->hasMany(Job::class, 'customer_id');
    }

    public function contractor(): HasOne
    {
        return $this->hasOne(Contractor::class);
    }

    public function customer(): HasOne
    {
        return $this->hasOne(Customer::class);
    }

    public function isOwner(): bool
    {
        return $this->role === 'owner';
    }

    public function isAiSuperAdmin(): bool
    {
        return $this->role === 'ai_super_admin';
    }

    public static function aiSuperAdmin(): ?self
    {
        return static::where('role', 'ai_super_admin')->first();
    }
}
