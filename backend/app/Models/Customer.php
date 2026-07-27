<?php

namespace App\Models;

use App\Models\Concerns\HasTestData;
use App\Services\Customers\CustomerValidationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    use HasTestData;

    public const COMM_PREFS = ['sms', 'email', 'both', 'none'];

    protected $fillable = [
        'user_id',
        'name',
        'phone',
        'phone_normalized',
        'email',
        'address',
        'portal_link_status',
        'data_quality_flags',
        'duplicate_group_id',
        'is_duplicate_primary',
        'merged_into_customer_id',
        'merged_at',
        'communication_preference',
        'do_not_contact',
        'consent_source',
        'consent_recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'portal_link_status' => 'boolean',
            'data_quality_flags' => 'array',
            'is_duplicate_primary' => 'boolean',
            'do_not_contact' => 'boolean',
            'merged_at' => 'datetime',
            'consent_recorded_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Customer $customer) {
            if ($customer->is_test_data) {
                return;
            }
            if ($customer->isDirty('phone')) {
                $validation = app(CustomerValidationService::class);
                $e164 = $validation->normalizePhoneE164($customer->phone);
                $customer->phone_normalized = $e164;
                if ($e164) {
                    $customer->phone = $e164;
                }
            }
            if ($customer->isDirty(['name', 'phone', 'email', 'address']) || $customer->data_quality_flags === null) {
                $flags = app(CustomerValidationService::class)->evaluateFlags(
                    $customer->name,
                    $customer->phone,
                    $customer->email,
                    $customer->address,
                );
                $customer->data_quality_flags = $flags === [] ? null : $flags;
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(self::class, 'merged_into_customer_id');
    }

    public function mergeLogsAsPrimary(): HasMany
    {
        return $this->hasMany(CustomerMergeLog::class, 'primary_customer_id');
    }

    public function hasQualityFlags(): bool
    {
        return is_array($this->data_quality_flags) && $this->data_quality_flags !== [];
    }

    public function scopeActiveDirectory(Builder $query): Builder
    {
        return $query
            ->whereNull('merged_into_customer_id')
            ->where(function (Builder $q) {
                $q->whereNull('duplicate_group_id')
                    ->orWhere('is_duplicate_primary', true);
            })
            ->where(function (Builder $q) {
                $q->whereNull('data_quality_flags')
                    ->orWhereJsonLength('data_quality_flags', 0);
            });
    }

    public function scopeNeedsReview(Builder $query): Builder
    {
        return $query
            ->whereNull('merged_into_customer_id')
            ->whereNotNull('data_quality_flags')
            ->whereJsonLength('data_quality_flags', '>', 0);
    }

    public function scopePossibleDuplicates(Builder $query): Builder
    {
        return $query
            ->whereNull('merged_into_customer_id')
            ->whereNotNull('duplicate_group_id');
    }
}
