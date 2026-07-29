<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Models\Concerns\HasTestData;
use App\Services\Contractors\ContractorProfileCompleteness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Contractor extends Model
{
    use HasTestData;

    public const STATES = [
        'invited',
        'profile_incomplete',
        'approved',
        'suspended',
        'deactivated',
    ];

    protected $fillable = [
        'user_id',
        'legal_name',
        'operating_name',
        'contact_name',
        'phone',
        'email',
        'services',
        'cities',
        'working_hours',
        'blackout_ranges',
        'min_notice_hours',
        'daily_capacity',
        'availability_paused',
        'availability_paused_until',
        'availability_notes',
        'wcb_status',
        'wcb_expiry_date',
        'wcb_file_url',
        'liability_insurance_status',
        'insurance_expiry_date',
        'insurance_file_url',
        'approval_status',
        'state',
        'payment_info',
        'admin_notes',
    ];

    protected $appends = ['missing_steps', 'display_name'];

    protected function casts(): array
    {
        return [
            'services' => 'array',
            'cities' => 'array',
            'working_hours' => 'array',
            'blackout_ranges' => 'array',
            'payment_info' => 'array',
            'availability_paused' => 'boolean',
            'availability_paused_until' => DateOnly::class,
            'wcb_expiry_date' => DateOnly::class,
            'insurance_expiry_date' => DateOnly::class,
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Contractor $contractor) {
            // Manual lock states must not be auto-derived away.
            if (in_array($contractor->getAttribute('state'), ['suspended', 'deactivated'], true)) {
                $contractor->approval_status = app(ContractorProfileCompleteness::class)
                    ->syncApprovalStatus((string) $contractor->state);

                return;
            }

            // If legacy approval_status was set to suspended, mirror state.
            if ($contractor->isDirty('approval_status') && $contractor->approval_status === 'suspended') {
                $contractor->state = 'suspended';

                return;
            }

            $completeness = app(ContractorProfileCompleteness::class);

            if ($contractor->isDirty('approval_status') && $contractor->approval_status === 'approved'
                && ! $contractor->isDirty('state')) {
                if ($completeness->isCompliant($contractor) && $completeness->missingSteps($contractor) === []) {
                    $contractor->state = 'approved';
                } else {
                    $contractor->state = $completeness->deriveState($contractor);
                    $contractor->approval_status = $completeness->syncApprovalStatus($contractor->state);
                }

                return;
            }

            $contractor->state = $completeness->deriveState($contractor);
            $contractor->approval_status = $completeness->syncApprovalStatus($contractor->state);
        });
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ContractorDocument::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function jobs(): HasMany
    {
        return $this->hasMany(Job::class, 'contractor_profile_id');
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(Payout::class, 'contractor_profile_id');
    }

    public function getDisplayNameAttribute(): string
    {
        return $this->legal_name
            ?: $this->operating_name
            ?: $this->contact_name
            ?: $this->user?->name
            ?: '—';
    }

    public function getMissingStepsAttribute(): array
    {
        return app(ContractorProfileCompleteness::class)->missingSteps($this);
    }

    public function scopeAssignable($query)
    {
        return $query->where('state', 'approved')
            ->where('wcb_status', 'approved')
            ->where('liability_insurance_status', 'approved');
    }

    public function scopeInDirectory($query)
    {
        return $query->where('state', '!=', 'deactivated');
    }
}
