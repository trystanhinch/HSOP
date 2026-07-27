<?php

namespace App\Services\Authorization;

use App\Models\AuditLog;
use App\Models\AvailabilityWindow;
use App\Models\Booking;
use App\Models\BookingHold;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Job;
use App\Models\Lead;
use App\Models\PmBrandAssignment;
use App\Models\Quote;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Audit PM-01 / PM-02 — authoritative PM authorization.
 *
 * Decisions:
 * 1A — pm_brand_assignments pivot; empty = no brand access
 * 2A — own-work-only (assigned_pm_id / pm_id = self)
 * 3A — availability: assigned brands' brand-level windows + own pm_id windows
 * 4A — customers only if tied to PM's own leads/jobs
 */
class PmAuthorizationService
{
    /**
     * @return Collection<int, int>
     */
    public function assignedBrandIds(User $user): Collection
    {
        if ($user->role !== 'pm') {
            return collect();
        }

        return PmBrandAssignment::query()
            ->where('user_id', $user->id)
            ->pluck('brand_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
    }

    public function hasBrandAccess(User $user, int $brandId): bool
    {
        if ($user->role === 'owner') {
            return true;
        }
        if ($user->role !== 'pm') {
            return false;
        }

        return $this->assignedBrandIds($user)->contains($brandId);
    }

    /**
     * Reject unauthorized brand_id query/body manipulation.
     */
    public function assertBrandAccess(User $user, ?int $brandId, string $context = 'brand'): void
    {
        if ($user->role !== 'pm' || $brandId === null) {
            return;
        }

        if (! $this->hasBrandAccess($user, $brandId)) {
            $this->logDenied($user, 'brand', $brandId, $context, ['brand_id' => $brandId]);
            throw new HttpException(403, 'Unauthorized brand access.');
        }
    }

    public function assertLeadAccess(User $user, Lead $lead): void
    {
        if ($user->role !== 'pm') {
            return;
        }
        if ((int) $lead->assigned_pm_id !== (int) $user->id) {
            $this->logDenied($user, 'lead', $lead->id, 'lead_access', [
                'assigned_pm_id' => $lead->assigned_pm_id,
            ]);
            throw new HttpException(403, 'Unauthorized.');
        }
        if ($lead->brand_id) {
            $this->assertBrandAccess($user, (int) $lead->brand_id, 'lead_brand');
        }
    }

    public function assertJobAccess(User $user, Job $job): void
    {
        if ($user->role !== 'pm') {
            return;
        }
        if ((int) $job->pm_id !== (int) $user->id) {
            $this->logDenied($user, 'job', $job->id, 'job_access', ['pm_id' => $job->pm_id]);
            throw new HttpException(403, 'Unauthorized.');
        }
        $job->loadMissing('lead:id,brand_id');
        if ($job->lead?->brand_id) {
            $this->assertBrandAccess($user, (int) $job->lead->brand_id, 'job_brand');
        }
    }

    public function assertQuoteAccess(User $user, Quote $quote): void
    {
        if ($user->role !== 'pm') {
            return;
        }
        $quote->loadMissing('job:id,pm_id,lead_id');
        if (! $quote->job || (int) $quote->job->pm_id !== (int) $user->id) {
            $this->logDenied($user, 'quote', $quote->id, 'quote_access', [
                'job_pm_id' => $quote->job?->pm_id,
            ]);
            throw new HttpException(403, 'Unauthorized.');
        }
        $this->assertJobAccess($user, $quote->job);
    }

    public function assertInvoiceAccess(User $user, Invoice $invoice): void
    {
        if ($user->role !== 'pm') {
            return;
        }
        $invoice->loadMissing('job:id,pm_id,lead_id');
        if (! $invoice->job || (int) $invoice->job->pm_id !== (int) $user->id) {
            $this->logDenied($user, 'invoice', $invoice->id, 'invoice_access', [
                'job_pm_id' => $invoice->job?->pm_id,
            ]);
            throw new HttpException(403, 'Unauthorized.');
        }
        $this->assertJobAccess($user, $invoice->job);
    }

    public function assertCustomerAccess(User $user, Customer $customer): void
    {
        if ($user->role !== 'pm') {
            return;
        }
        if (! $this->customerIsInPmScope($user, $customer)) {
            $this->logDenied($user, 'customer', $customer->id, 'customer_access');
            throw new HttpException(403, 'Unauthorized.');
        }
    }

    public function customerIsInPmScope(User $user, Customer $customer): bool
    {
        $userId = $customer->user_id;
        if (! $userId) {
            return false;
        }

        $viaJobs = Job::query()->where('pm_id', $user->id)->where('customer_id', $userId)->exists();
        if ($viaJobs) {
            return true;
        }

        return Lead::query()
            ->where('assigned_pm_id', $user->id)
            ->where(function ($q) use ($userId, $customer) {
                $q->where('customer_id', $userId)
                    ->orWhere('customer_id', $customer->id);
            })
            ->exists();
    }

    public function scopeLeadsForPm(Builder $query, User $user): Builder
    {
        return $query->where('assigned_pm_id', $user->id);
    }

    public function scopeJobsForPm(Builder $query, User $user): Builder
    {
        return $query->where('pm_id', $user->id);
    }

    public function scopeQuotesForPm(Builder $query, User $user): Builder
    {
        return $query->whereHas('job', fn ($q) => $q->where('pm_id', $user->id));
    }

    public function scopeInvoicesForPm(Builder $query, User $user): Builder
    {
        return $query->whereHas('job', fn ($q) => $q->where('pm_id', $user->id));
    }

    public function scopeCustomersForPm(Builder $query, User $user): Builder
    {
        $jobCustomerIds = Job::query()->where('pm_id', $user->id)->whereNotNull('customer_id')->pluck('customer_id');
        $leadCustomerUserIds = Lead::query()->where('assigned_pm_id', $user->id)->whereNotNull('customer_id')->pluck('customer_id');

        // customer_id on jobs/leads is usually users.id; also allow customers.id matches via user_id
        $userIds = $jobCustomerIds->merge($leadCustomerUserIds)->unique()->filter()->values();

        return $query->where(function ($q) use ($userIds) {
            $q->whereIn('user_id', $userIds)
                ->orWhereIn('id', $userIds); // defensive if any FK pointed at customers.id
        });
    }

    /**
     * PM-02: can view/edit window if brand assigned AND (pm_id null brand-level OR pm_id = self).
     */
    public function canManageWindow(User $user, AvailabilityWindow $window): bool
    {
        if ($user->role === 'owner') {
            return true;
        }
        if ($user->role !== 'pm') {
            return false;
        }
        if (! $this->hasBrandAccess($user, (int) $window->brand_id)) {
            return false;
        }
        if ($window->pm_id === null || (int) $window->pm_id === 0) {
            return true; // brand-level window (3A)
        }

        return (int) $window->pm_id === (int) $user->id;
    }

    public function assertWindowAccess(User $user, AvailabilityWindow $window, string $action = 'edit'): void
    {
        if ($user->role === 'owner') {
            return;
        }
        if ($user->role !== 'pm' || ! $this->canManageWindow($user, $window)) {
            $this->logDenied($user, 'availability_window', $window->id, $action, [
                'brand_id' => $window->brand_id,
                'pm_id' => $window->pm_id,
            ]);
            throw new HttpException(403, 'Unauthorized availability window access.');
        }
    }

    /**
     * Active bookings/holds that would be orphaned by deactivating this window.
     *
     * @return array{bookings: int, holds: int, blocked: bool, message: ?string}
     */
    public function deactivationGuard(AvailabilityWindow $window): array
    {
        $resourceKey = $window->resourceKey();
        $activeBookings = Booking::query()
            ->where('brand_id', $window->brand_id)
            ->where('resource_key', $resourceKey)
            ->whereIn('status', ['confirmed', 'scheduled', 'active', 'held'])
            ->where('slot_start', '>=', now()->subDay())
            ->count();

        $activeHolds = BookingHold::query()
            ->where('brand_id', $window->brand_id)
            ->where('resource_key', $resourceKey)
            ->where('status', 'held')
            ->where('held_until', '>', now())
            ->count();

        $blocked = $activeBookings > 0 || $activeHolds > 0;

        return [
            'bookings' => $activeBookings,
            'holds' => $activeHolds,
            'blocked' => $blocked,
            'message' => $blocked
                ? "Cannot deactivate: {$activeBookings} active booking(s) and {$activeHolds} active hold(s) use this window. Reschedule or cancel them first."
                : null,
        ];
    }

    /**
     * Owner replaces PM brand set. Immediate effect (no cache). Audited.
     *
     * @param  list<int>  $brandIds
     */
    public function syncAssignments(User $pm, array $brandIds, User $actor): array
    {
        if ($pm->role !== 'pm') {
            throw new HttpException(422, 'Brand assignments only apply to PM users.');
        }
        if ($actor->role !== 'owner') {
            throw new HttpException(403, 'Only owners can change PM brand assignments.');
        }

        $brandIds = collect($brandIds)->map(fn ($id) => (int) $id)->unique()->filter()->values();
        $before = $this->assignedBrandIds($pm)->all();

        PmBrandAssignment::query()->where('user_id', $pm->id)->delete();
        foreach ($brandIds as $brandId) {
            if (! Brand::query()->whereKey($brandId)->exists()) {
                continue;
            }
            PmBrandAssignment::create([
                'user_id' => $pm->id,
                'brand_id' => $brandId,
                'assigned_by' => $actor->id,
                'assigned_at' => now(),
            ]);
        }

        $after = $this->assignedBrandIds($pm)->all();

        AuditLog::create([
            'user_id' => $actor->id,
            'user_role' => $actor->role,
            'object_type' => 'pm_brand_assignment',
            'object_id' => $pm->id,
            'action_type' => 'pm_brand_assignments_changed',
            'previous_value' => ['brand_ids' => $before],
            'new_value' => ['brand_ids' => $after],
            'reason' => 'Owner updated PM brand assignments',
            'created_at' => now(),
        ]);

        return [
            'user_id' => $pm->id,
            'brand_ids' => $after,
            'brands' => Brand::query()->whereIn('id', $after)->get(['id', 'slug', 'company_name']),
        ];
    }

    public function logDenied(User $user, string $objectType, int $objectId, string $context, array $meta = []): void
    {
        AuditLog::create([
            'user_id' => $user->id,
            'user_role' => $user->role,
            'object_type' => $objectType,
            'object_id' => $objectId,
            'action_type' => 'pm_unauthorized_access_blocked',
            'previous_value' => null,
            'new_value' => array_merge(['context' => $context], $meta),
            'reason' => 'PM attempted unauthorized access',
            'created_at' => now(),
        ]);
    }

    public function logAvailabilityChange(User $user, AvailabilityWindow $window, string $action, ?array $before = null): void
    {
        AuditLog::create([
            'user_id' => $user->id,
            'user_role' => $user->role,
            'object_type' => 'availability_window',
            'object_id' => $window->id,
            'action_type' => $action,
            'previous_value' => $before,
            'new_value' => $window->fresh()?->toArray() ?? $window->toArray(),
            'created_at' => now(),
        ]);
    }
}
