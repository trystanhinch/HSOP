<?php

namespace App\Services\Booking;

use App\Models\AvailabilityWindow;
use App\Models\Booking;
use App\Models\BookingHold;
use App\Models\Brand;
use App\Models\IntakeSession;
use App\Models\Lead;
use App\Models\SiteVisit;
use App\Models\SlotClaim;
use App\Models\User;
use App\Mail\SiteVisitScheduledContractorMail;
use App\Mail\SiteVisitScheduledCustomerMail;
use App\Services\EmailService;
use App\Services\LeadCustomerResolver;
use App\Services\SmsMessageTemplates;
use App\Services\SmsService;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Slot availability + soft holds + confirmed bookings with DB-level conflict prevention.
 */
class BookingService
{
    public function __construct(
        private SmsService $sms,
        private EmailService $email,
        private LeadCustomerResolver $customerResolver,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function availableSlots(
        Brand $brand,
        ?string $serviceCategory = null,
        ?Carbon $from = null,
        ?int $horizonDays = null,
    ): array {
        $this->releaseExpiredHolds($brand->id);

        $tz = config('booking.default_timezone', 'America/Vancouver');
        $fromLocal = ($from?->copy() ?? now($tz))->timezone($tz)->startOfMinute();
        $horizon = $horizonDays ?? (int) config('booking.availability_horizon_days', 14);
        $untilLocal = $fromLocal->copy()->addDays($horizon)->endOfDay();

        $windows = AvailabilityWindow::query()
            ->where('brand_id', $brand->id)
            ->where('status', 'active')
            ->when($serviceCategory, function ($q) use ($serviceCategory) {
                $q->where(function ($inner) use ($serviceCategory) {
                    $inner->whereNull('service_category')
                        ->orWhere('service_category', $serviceCategory);
                });
            })
            ->get();

        $candidates = [];
        foreach ($windows as $window) {
            $windowTz = $window->timezone ?: $tz;
            foreach ($this->expandWindowSlots($window, $fromLocal->copy()->timezone($windowTz), $untilLocal->copy()->timezone($windowTz)) as $slot) {
                $key = $slot['resource_key'].'|'.$slot['slot_start'];
                $candidates[$key] = $slot;
            }
        }

        if ($candidates === []) {
            return [];
        }

        $starts = array_values(array_unique(array_column($candidates, 'slot_start_utc')));
        $activeClaims = SlotClaim::query()
            ->where('brand_id', $brand->id)
            ->whereIn('slot_start', $starts)
            ->where(function ($q) {
                $q->where('claim_type', 'booking')
                    ->orWhere(function ($h) {
                        $h->where('claim_type', 'hold')
                            ->where(function ($e) {
                                $e->whereNull('expires_at')->orWhere('expires_at', '>', now());
                            });
                    });
            })
            ->get()
            ->groupBy(fn (SlotClaim $c) => $c->resource_key.'|'.$c->slot_start->utc()->format('Y-m-d H:i:s'));

        $open = [];
        foreach ($candidates as $key => $slot) {
            $claimKey = $slot['resource_key'].'|'.Carbon::parse($slot['slot_start_utc'])->format('Y-m-d H:i:s');
            if ($activeClaims->has($claimKey)) {
                continue;
            }
            // Also skip past slots
            if (Carbon::parse($slot['slot_start_utc'])->lte(now())) {
                continue;
            }
            $open[] = [
                'slot_start' => $slot['slot_start_iso'],
                'slot_end' => $slot['slot_end_iso'],
                'slot_start_local' => $slot['slot_start_local'],
                'slot_end_local' => $slot['slot_end_local'],
                'timezone' => $slot['timezone'],
                'resource_key' => $slot['resource_key'],
                'pm_id' => $slot['pm_id'],
                'contractor_id' => $slot['contractor_id'],
                'service_category' => $slot['service_category'],
                'duration_minutes' => $slot['duration_minutes'],
            ];
        }

        usort($open, fn ($a, $b) => strcmp($a['slot_start'], $b['slot_start']));

        return $open;
    }

    /**
     * Soft-hold a slot for an intake session. Concurrent second claim fails via unique index.
     *
     * @throws RuntimeException when slot is no longer available
     */
    public function holdSlot(
        Brand $brand,
        IntakeSession $session,
        Carbon $slotStartUtc,
        Carbon $slotEndUtc,
        string $resourceKey,
        ?string $serviceCategory = null,
        ?int $pmId = null,
        ?int $contractorId = null,
    ): BookingHold {
        if ((int) $session->brand_id !== (int) $brand->id) {
            throw new RuntimeException('Intake session does not belong to this brand.');
        }

        $this->releaseExpiredHolds($brand->id);

        $ttl = (int) config('booking.hold_ttl_seconds', 600);
        $heldUntil = now()->addSeconds($ttl);

        try {
            return DB::transaction(function () use (
                $brand, $session, $slotStartUtc, $slotEndUtc, $resourceKey,
                $serviceCategory, $pmId, $contractorId, $heldUntil
            ) {
                // Serialize overlapping claim checks for this brand/resource
                SlotClaim::query()
                    ->where('brand_id', $brand->id)
                    ->where('resource_key', $resourceKey)
                    ->where('slot_start', '<', $slotEndUtc)
                    ->where('slot_end', '>', $slotStartUtc)
                    ->lockForUpdate()
                    ->get();

                $conflict = SlotClaim::query()
                    ->where('brand_id', $brand->id)
                    ->where('resource_key', $resourceKey)
                    ->where('slot_start', $slotStartUtc)
                    ->where(function ($q) {
                        $q->where('claim_type', 'booking')
                            ->orWhere(function ($h) {
                                $h->where('claim_type', 'hold')
                                    ->where(function ($e) {
                                        $e->whereNull('expires_at')->orWhere('expires_at', '>', now());
                                    });
                            });
                    })
                    ->exists();

                if ($conflict) {
                    throw new RuntimeException('That time slot is no longer available.');
                }

                // Release any prior active hold for this same session (slot change)
                $prior = BookingHold::query()
                    ->where('intake_session_id', $session->id)
                    ->where('status', 'held')
                    ->lockForUpdate()
                    ->get();
                foreach ($prior as $old) {
                    $this->cancelHoldInternal($old);
                }

                $hold = BookingHold::create([
                    'brand_id' => $brand->id,
                    'intake_session_id' => $session->id,
                    'lead_id' => null,
                    'hold_token' => Str::random(48),
                    'resource_key' => $resourceKey,
                    'pm_id' => $pmId,
                    'contractor_id' => $contractorId,
                    'service_category' => $serviceCategory,
                    'slot_start' => $slotStartUtc,
                    'slot_end' => $slotEndUtc,
                    'status' => 'held',
                    'held_until' => $heldUntil,
                ]);

                SlotClaim::create([
                    'brand_id' => $brand->id,
                    'resource_key' => $resourceKey,
                    'slot_start' => $slotStartUtc,
                    'slot_end' => $slotEndUtc,
                    'claim_type' => 'hold',
                    'claim_id' => $hold->id,
                    'expires_at' => $heldUntil,
                ]);

                $state = $session->conversation_state ?? [];
                $session->conversation_state = array_merge($state, [
                    'booking_hold_token' => $hold->hold_token,
                    'booking_slot' => [
                        'slot_start' => $slotStartUtc->toIso8601String(),
                        'slot_end' => $slotEndUtc->toIso8601String(),
                        'resource_key' => $resourceKey,
                    ],
                ]);
                $session->save();

                return $hold;
            });
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                throw new RuntimeException('That time slot is no longer available.');
            }
            throw $e;
        }
    }

    /**
     * Convert an active hold into a confirmed booking + SiteVisit (Milestone 4 structure).
     */
    public function confirmHoldForLead(BookingHold $hold, Lead $lead, bool $sendNotifications = true): Booking
    {
        try {
            return DB::transaction(function () use ($hold, $lead, $sendNotifications) {
                $hold = BookingHold::query()->whereKey($hold->id)->lockForUpdate()->firstOrFail();

                if ($hold->status === 'confirmed') {
                    $existing = Booking::query()->where('booking_hold_id', $hold->id)->first();
                    if ($existing) {
                        return $existing;
                    }
                }

                if ($hold->status !== 'held' || $hold->held_until->isPast()) {
                    throw new RuntimeException('Booking hold expired or is no longer valid.');
                }

                // Lock claim row
                $claim = SlotClaim::query()
                    ->where('brand_id', $hold->brand_id)
                    ->where('resource_key', $hold->resource_key)
                    ->where('slot_start', $hold->slot_start)
                    ->lockForUpdate()
                    ->first();

                if (! $claim || $claim->claim_type !== 'hold' || (int) $claim->claim_id !== (int) $hold->id) {
                    throw new RuntimeException('That time slot is no longer available.');
                }

                $tz = config('booking.default_timezone', 'America/Vancouver');
                $localStart = $hold->slot_start->copy()->timezone($tz);

                $pmId = $hold->pm_id ?? $lead->assigned_pm_id;
                $contractorId = $hold->contractor_id ?? $lead->site_visit_contractor_id;
                $matchResult = null;
                $autoMatched = false;
                $matchRule = null;
                $matchMeta = null;

                // Phase 5: thin auto-match when booking has no contractor yet
                if (! $contractorId) {
                    $brand = Brand::find($hold->brand_id);
                    $matchResult = app(ContractorBookingMatcher::class)->matchForLead($lead->fresh(), $brand);
                    if ($matchResult['matched'] && $matchResult['contractor_user_id']) {
                        $contractorId = (int) $matchResult['contractor_user_id'];
                        $autoMatched = true;
                        $matchRule = $matchResult['rule'];
                        $matchMeta = array_merge($matchResult['meta'], [
                            'reason' => $matchResult['reason'],
                            'eligible_count' => $matchResult['eligible_count'],
                        ]);
                    } else {
                        $matchRule = null;
                        $matchMeta = array_merge($matchResult['meta'] ?? [], [
                            'reason' => $matchResult['reason'] ?? 'No match',
                            'next_action_id' => $matchResult['next_action_id'] ?? null,
                        ]);
                    }
                }

                $customerId = $lead->customer_id ?: $this->customerResolver->resolveForLead($lead->fresh());
                $lead->refresh();

                if (! $lead->customer_portal_token) {
                    $lead->update(['customer_portal_token' => Str::random(64)]);
                    $lead->refresh();
                }

                $notesExtra = 'Booked via public intake.';
                if ($autoMatched && is_array($matchMeta)) {
                    $notesExtra .= ' Auto-matched contractor ('.$matchRule.'): '.($matchMeta['reason'] ?? '');
                } elseif (! $contractorId && is_array($matchMeta)) {
                    $notesExtra .= ' Contractor auto-match deferred: '.($matchMeta['reason'] ?? 'none eligible');
                }

                $lead->update([
                    'site_visit_date' => $localStart->toDateString(),
                    'site_visit_time' => $localStart->format('H:i'),
                    'site_visit_contractor_id' => $contractorId,
                    'assigned_contractor_id' => $contractorId ?? $lead->assigned_contractor_id,
                    'site_visit_notes' => trim(($lead->site_visit_notes ? $lead->site_visit_notes."\n" : '').$notesExtra),
                    'status' => 'site_visit_scheduled',
                    'assigned_pm_id' => $pmId ?? $lead->assigned_pm_id,
                ]);

                $siteVisit = SiteVisit::updateOrCreate(
                    ['lead_id' => $lead->id],
                    [
                        'pm_id' => $pmId,
                        'contractor_id' => $contractorId,
                        'customer_id' => $customerId,
                        'visit_date' => $localStart->toDateString(),
                        'visit_time' => $localStart->format('H:i'),
                        'notes' => 'Confirmed from public intake booking hold.'
                            .($autoMatched ? ' '.$matchRule : ''),
                        'status' => 'scheduled',
                    ]
                );

                $booking = Booking::create([
                    'brand_id' => $hold->brand_id,
                    'lead_id' => $lead->id,
                    'job_id' => null,
                    'intake_session_id' => $hold->intake_session_id,
                    'booking_hold_id' => $hold->id,
                    'site_visit_id' => $siteVisit->id,
                    'resource_key' => $hold->resource_key,
                    'pm_id' => $pmId,
                    'contractor_id' => $contractorId,
                    'auto_matched' => $autoMatched,
                    'match_rule' => $matchRule,
                    'match_meta' => $matchMeta,
                    'service_category' => $hold->service_category ?? $lead->service_category,
                    'slot_start' => $hold->slot_start,
                    'slot_end' => $hold->slot_end,
                    'timezone' => $tz,
                    'status' => 'confirmed',
                    'confirmed_at' => now(),
                ]);

                $claim->update([
                    'claim_type' => 'booking',
                    'claim_id' => $booking->id,
                    'expires_at' => null,
                ]);

                $hold->update([
                    'status' => 'confirmed',
                    'lead_id' => $lead->id,
                ]);

                if ($sendNotifications) {
                    $this->sendSiteVisitNotifications($lead->fresh(), $siteVisit->fresh(), $contractorId);
                }

                return $booking;
            });
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                throw new RuntimeException('That time slot is no longer available.');
            }
            throw $e;
        }
    }

    public function releaseExpiredHolds(?int $brandId = null): int
    {
        $query = BookingHold::query()
            ->where('status', 'held')
            ->where('held_until', '<=', now());

        if ($brandId) {
            $query->where('brand_id', $brandId);
        }

        $count = 0;
        foreach ($query->get() as $hold) {
            DB::transaction(function () use ($hold, &$count) {
                $locked = BookingHold::query()->whereKey($hold->id)->lockForUpdate()->first();
                if (! $locked || $locked->status !== 'held') {
                    return;
                }
                $this->cancelHoldInternal($locked, 'expired');
                $count++;
            });
        }

        // Orphan expired claims
        SlotClaim::query()
            ->where('claim_type', 'hold')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->when($brandId, fn ($q) => $q->where('brand_id', $brandId))
            ->delete();

        return $count;
    }

    public function cancelHold(BookingHold $hold): void
    {
        DB::transaction(function () use ($hold) {
            $locked = BookingHold::query()->whereKey($hold->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'held') {
                return;
            }
            $this->cancelHoldInternal($locked, 'cancelled');
        });
    }

    private function cancelHoldInternal(BookingHold $hold, string $status = 'cancelled'): void
    {
        SlotClaim::query()
            ->where('claim_type', 'hold')
            ->where('claim_id', $hold->id)
            ->delete();

        $hold->update(['status' => $status]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function expandWindowSlots(AvailabilityWindow $window, Carbon $fromLocal, Carbon $untilLocal): array
    {
        $tz = $window->timezone ?: config('booking.default_timezone', 'America/Vancouver');
        $duration = max(15, (int) $window->slot_duration_minutes);
        $slots = [];

        $cursor = $fromLocal->copy()->startOfDay();
        $endDay = $untilLocal->copy()->startOfDay();

        while ($cursor->lte($endDay)) {
            $matches = false;
            if ($window->specific_date) {
                $matches = $cursor->toDateString() === $window->specific_date->toDateString();
            } elseif ($window->day_of_week !== null) {
                $matches = (int) $cursor->dayOfWeek === (int) $window->day_of_week;
            }

            if ($matches) {
                $start = Carbon::parse($cursor->toDateString().' '.$window->start_time, $tz);
                $windowEnd = Carbon::parse($cursor->toDateString().' '.$window->end_time, $tz);
                while ($start->copy()->addMinutes($duration)->lte($windowEnd)) {
                    $slotEnd = $start->copy()->addMinutes($duration);
                    $slots[] = [
                        'resource_key' => $window->resourceKey(),
                        'pm_id' => $window->pm_id,
                        'contractor_id' => $window->contractor_id,
                        'service_category' => $window->service_category,
                        'duration_minutes' => $duration,
                        'timezone' => $tz,
                        'slot_start_local' => $start->toIso8601String(),
                        'slot_end_local' => $slotEnd->toIso8601String(),
                        'slot_start_iso' => $start->copy()->utc()->toIso8601String(),
                        'slot_end_iso' => $slotEnd->copy()->utc()->toIso8601String(),
                        'slot_start_utc' => $start->copy()->utc()->format('Y-m-d H:i:s'),
                        'slot_start' => $start->copy()->utc()->format('Y-m-d H:i:s'),
                    ];
                    $start->addMinutes($duration);
                }
            }
            $cursor->addDay();
        }

        return $slots;
    }

    private function sendSiteVisitNotifications(Lead $lead, SiteVisit $siteVisit, ?int $contractorId): void
    {
        $customerPortalUrl = SmsMessageTemplates::customerPortalUrl($lead->customer_portal_token);
        $customerUser = $lead->customer_id ? User::find($lead->customer_id) : null;

        $this->sms->send(
            SmsService::phoneForUser($customerUser) ?? $lead->phone,
            SmsMessageTemplates::siteVisitCustomer(
                $lead,
                $siteVisit->visit_date,
                $siteVisit->visit_time,
                $customerPortalUrl
            ),
            'site_visit_scheduled',
            $customerUser?->id,
            null
        );

        if ($customerUser?->email || $lead->email) {
            $this->email->sendMailable(
                $customerUser?->email ?? $lead->email,
                new SiteVisitScheduledCustomerMail($lead, $siteVisit, $customerPortalUrl),
                'site_visit_scheduled',
                $customerUser?->id,
                null
            );
        }

        if ($contractorId) {
            $contractor = User::find($contractorId);
            if ($contractor) {
                $contractorPortalUrl = SmsMessageTemplates::contractorDashboardUrl();
                $this->sms->sendToUser(
                    $contractor,
                    SmsMessageTemplates::siteVisitContractor(
                        $contractor,
                        $lead,
                        $siteVisit->visit_date,
                        $siteVisit->visit_time,
                        $contractorPortalUrl
                    ),
                    'site_visit_contractor_assigned',
                    null
                );
                if ($contractor->email) {
                    $this->email->sendMailable(
                        $contractor->email,
                        new SiteVisitScheduledContractorMail($lead, $siteVisit, $contractorPortalUrl),
                        'site_visit_contractor_assigned',
                        $contractor->id,
                        null
                    );
                }
            }
        }
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        $code = (string) ($e->errorInfo[1] ?? $e->getCode());

        // MySQL duplicate key / Postgres unique_violation
        return $code === '1062' || $code === '23505' || str_contains(strtolower($e->getMessage()), 'duplicate');
    }
}
