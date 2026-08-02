<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use App\Models\Concerns\HasTestData;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasTestData, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'password',
        'role',
        'brand_id',
        'status',
        'sms_enabled',
        'stripe_account_id',
        'stripe_onboarding_status',
        'stripe_requirements_due',
        'stripe_payout_ready',
        'last_login_at',
        'invited_at',
        'invitation_status',
        'suspended_at',
        'is_developer',
        'can_finalize_learning_eligibility',
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
            'stripe_requirements_due' => 'array',
            'stripe_payout_ready' => 'boolean',
            'last_login_at' => 'datetime',
            'invited_at' => 'datetime',
            'suspended_at' => 'datetime',
            'is_developer' => 'boolean',
            'can_finalize_learning_eligibility' => 'boolean',
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

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function pmBrandAssignments(): HasMany
    {
        return $this->hasMany(PmBrandAssignment::class, 'user_id');
    }

    public function assignedBrands(): BelongsToMany
    {
        return $this->belongsToMany(Brand::class, 'pm_brand_assignments', 'user_id', 'brand_id')
            ->withTimestamps()
            ->withPivot(['assigned_by', 'assigned_at']);
    }

    public function isOwner(): bool
    {
        return $this->role === 'owner';
    }

    /**
     * Milestone 6B Phase 3 — final learning eligibility authority.
     * Owners always can; named delegates via can_finalize_learning_eligibility (owner-settable).
     */
    public function canFinalizeLearningEligibility(): bool
    {
        return $this->isOwner() || (bool) $this->can_finalize_learning_eligibility;
    }

    public function isDeveloper(): bool
    {
        return $this->isOwner() && (bool) $this->is_developer;
    }

    public function isActive(): bool
    {
        return ($this->status ?? 'active') === 'active';
    }

    public function isContentEditor(): bool
    {
        return $this->role === 'content_editor';
    }

    public function isAiSuperAdmin(): bool
    {
        return $this->role === 'ai_super_admin';
    }

    public function isExternalReviewAi(): bool
    {
        return $this->role === 'external_review_ai';
    }

    public function isLearningAi(): bool
    {
        return $this->role === 'learning_ai';
    }

    public static function aiSuperAdmin(): ?self
    {
        return static::where('role', 'ai_super_admin')->first();
    }

    public static function externalReviewAi(): ?self
    {
        return static::where('role', 'external_review_ai')->first();
    }

    public static function learningAi(): ?self
    {
        return static::where('role', 'learning_ai')->first();
    }

    /**
     * Safe auth payload for login /me (no secrets).
     *
     * @return array<string, mixed>
     */
    public function toAuthArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role,
            'status' => $this->status,
            'brand_id' => $this->brand_id,
            'is_developer' => (bool) $this->is_developer,
            'can_finalize_learning_eligibility' => (bool) $this->can_finalize_learning_eligibility,
            'can_finalize_learning' => $this->canFinalizeLearningEligibility(),
            'brand' => $this->relationLoaded('brand') && $this->brand
                ? [
                    'id' => $this->brand->id,
                    'company_name' => $this->brand->company_name,
                    'domain' => $this->brand->domain,
                    'slug' => $this->brand->slug,
                ]
                : null,
            'app_env' => config('app.env', 'production'),
        ];
    }
}
