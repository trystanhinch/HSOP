<?php

namespace App\Services\Calendar;

use App\Models\Booking;
use App\Models\BookingHold;
use App\Models\Job;
use App\Models\PmMeeting;
use App\Models\SiteVisit;
use App\Models\User;
use App\Services\Contractors\ContractorAssignmentService;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * A-31 — Unified calendar feed for admin / PM / contractor views.
 * Same event shape + deep-links (CT-01) across roles.
 */
class CalendarService
{
    public function __construct(
        private ContractorAssignmentService $assignments,
        private CalendarConflictService $conflicts,
    ) {}

    /**
     * @return array{
     *   month: string,
     *   view: string,
     *   timezone: string,
     *   events: Collection<int, array<string, mixed>>,
     *   all: Collection<int, array<string, mixed>>,
     *   site_visits: Collection<int, array<string, mixed>>,
     *   jobs: Collection<int, array<string, mixed>>,
     *   meetings: Collection<int, array<string, mixed>>,
     *   booking_holds: Collection<int, array<string, mixed>>,
     *   bookings: Collection<int, array<string, mixed>>,
     *   conflicts: list<array<string, mixed>>
     * }
     */
    public function forUser(User $user, string $month, string $view = 'month'): array
    {
        [$year, $mon] = array_map('intval', explode('-', $month));
        $tz = config('booking.default_timezone', config('app.timezone', 'America/Vancouver'));

        $meetings = $this->pmMeetings($user, $mon, $year);
        $siteVisits = $this->siteVisits($user, $mon, $year);
        $jobs = $this->jobs($user, $mon, $year);
        $holds = $this->bookingHolds($user, $mon, $year);
        $bookings = $this->bookings($user, $mon, $year);

        $events = $siteVisits
            ->concat($jobs)
            ->concat($meetings)
            ->concat($holds)
            ->concat($bookings)
            ->sortBy(fn ($e) => ($e['date'] ?? '').' '.($e['time'] ?? ''))
            ->values();

        $conflictList = $this->conflicts->detectInEvents($events);

        return [
            'month' => $month,
            'view' => $view,
            'timezone' => $tz,
            'events' => $events,
            'all' => $events,
            'site_visits' => $siteVisits->values(),
            'jobs' => $jobs->values(),
            'meetings' => $meetings->values(),
            'booking_holds' => $holds->values(),
            'bookings' => $bookings->values(),
            'conflicts' => $conflictList,
        ];
    }

    /**
     * Canonical site-visit event payload (identical across roles).
     *
     * @return array<string, mixed>
     */
    public function siteVisitEvent(SiteVisit $sv): array
    {
        $address = $sv->lead->address ?? '';
        $maps = $address !== ''
            ? 'https://www.google.com/maps/dir/?api=1&destination='.rawurlencode($address)
            : null;

        return [
            'type' => 'site_visit',
            'id' => $sv->id,
            'lead_id' => $sv->lead_id,
            'title' => 'Site Visit — '.($sv->lead->contact_name ?? 'Customer'),
            'date' => $sv->visit_date?->format('Y-m-d'),
            'time' => is_string($sv->visit_time) ? substr($sv->visit_time, 0, 5) : $sv->visit_time,
            'end_time' => null,
            'status' => $sv->status,
            'address' => $address,
            'customer_name' => $sv->lead->contact_name ?? '',
            'customer_phone' => $sv->lead->phone ?? null,
            'pm_id' => $sv->pm_id,
            'pm_name' => $sv->pm?->name ?? $sv->lead?->assignedPm?->name ?? '',
            'contractor_id' => $sv->contractor_id,
            'contractor_name' => $sv->contractor?->name ?? '',
            'url' => '/leads/'.$sv->lead_id,
            'directions_url' => $maps,
            'travel_buffer_minutes' => 0,
            'color' => 'indigo',
        ];
    }

    private function pmMeetings(User $user, int $mon, int $year): Collection
    {
        $q = PmMeeting::with(['pm:id,name'])
            ->whereMonth('meeting_date', $mon)
            ->whereYear('meeting_date', $year);

        if ($user->role === 'pm') {
            $q->where('pm_id', $user->id);
        } elseif ($user->role === 'contractor') {
            return collect();
        }

        return $q->orderBy('meeting_date')->orderBy('meeting_time')->get()->map(fn ($m) => [
            'type' => 'pm_meeting',
            'id' => $m->id,
            'title' => $m->title,
            'date' => $m->meeting_date?->format('Y-m-d'),
            'time' => $m->meeting_time ? substr((string) $m->meeting_time, 0, 5) : null,
            'notes' => $m->notes,
            'pm_id' => $m->pm_id,
            'pm_name' => $m->pm?->name ?? '',
            'url' => null,
            'color' => 'purple',
        ]);
    }

    private function siteVisits(User $user, int $mon, int $year): Collection
    {
        if ($user->role === 'contractor') {
            return $this->assignments->scheduleSiteVisitsForMonth($user, $mon, $year)
                ->map(function (array $row) {
                    // Ensure CT-01 deep-link + directions stay consistent.
                    $address = $row['address'] ?? '';
                    $row['directions_url'] = $address !== ''
                        ? 'https://www.google.com/maps/dir/?api=1&destination='.rawurlencode($address)
                        : null;
                    $row['travel_buffer_minutes'] = $row['travel_buffer_minutes'] ?? 0;

                    return $row;
                });
        }

        $q = SiteVisit::with([
            'lead:id,contact_name,phone,address,service_category,status,assigned_pm_id',
            'lead.assignedPm:id,name',
            'pm:id,name',
            'contractor:id,name',
        ])
            ->whereMonth('visit_date', $mon)
            ->whereYear('visit_date', $year)
            ->where('status', '!=', 'cancelled');

        if ($user->role === 'pm') {
            $q->where('pm_id', $user->id);
        }

        return $q->get()->map(fn (SiteVisit $sv) => $this->siteVisitEvent($sv));
    }

    private function jobs(User $user, int $mon, int $year): Collection
    {
        $q = Job::with(['customer:id,name,phone', 'pm:id,name', 'contractor:id,name'])
            ->whereNotNull('scheduled_start_date')
            ->whereMonth('scheduled_start_date', $mon)
            ->whereYear('scheduled_start_date', $year);

        if ($user->role === 'pm') {
            $q->where('pm_id', $user->id);
        } elseif ($user->role === 'contractor') {
            $q->where('contractor_id', $user->id);
        }

        return $q->get()->map(function (Job $j) {
            $address = $j->address ?? '';

            return [
                'type' => 'job',
                'id' => $j->id,
                'title' => $j->job_title ?? ($j->customer?->name ?? 'Job'),
                'date' => $j->scheduled_start_date?->format('Y-m-d'),
                'time' => $j->scheduled_start_time,
                'end_time' => null,
                'end_date' => $j->scheduled_end_date?->format('Y-m-d'),
                'status' => $j->status,
                'address' => $address,
                'customer_name' => $j->customer?->name ?? '',
                'customer_phone' => $j->customer?->phone ?? null,
                'contractor_id' => $j->contractor_id,
                'contractor_name' => $j->contractor?->name ?? '',
                'pm_id' => $j->pm_id,
                'pm_name' => $j->pm?->name ?? '',
                'url' => '/jobs/'.$j->id,
                'directions_url' => $address !== ''
                    ? 'https://www.google.com/maps/dir/?api=1&destination='.rawurlencode($address)
                    : null,
                'color' => in_array($j->status, ['in_progress', 'progress_updated'], true) ? 'blue' : 'yellow',
            ];
        });
    }

    private function bookingHolds(User $user, int $mon, int $year): Collection
    {
        if ($user->role === 'contractor') {
            return collect();
        }

        $start = Carbon::create($year, $mon, 1)->startOfMonth();
        $end = $start->copy()->endOfMonth();

        $q = BookingHold::query()
            ->with(['brand:id,domain,company_name'])
            ->where('status', 'held')
            ->where('held_until', '>', now())
            ->whereBetween('slot_start', [$start, $end]);

        if ($user->role === 'pm') {
            $ids = app(\App\Services\Authorization\PmAuthorizationService::class)->assignedBrandIds($user);
            $q->whereIn('brand_id', $ids->isEmpty() ? [-1] : $ids);
        }

        return $q->limit(200)->get()->map(fn (BookingHold $h) => [
            'type' => 'booking_hold',
            'id' => $h->id,
            'title' => 'Hold — '.($h->brand?->company_name ?? 'Brand'),
            'date' => Carbon::parse($h->slot_start)->format('Y-m-d'),
            'time' => Carbon::parse($h->slot_start)->format('H:i'),
            'status' => $h->status,
            'brand_id' => $h->brand_id,
            'url' => '/availability',
            'color' => 'orange',
        ]);
    }

    private function bookings(User $user, int $mon, int $year): Collection
    {
        if ($user->role === 'contractor') {
            return collect();
        }

        $start = Carbon::create($year, $mon, 1)->startOfMonth();
        $end = $start->copy()->endOfMonth();

        $q = Booking::query()
            ->with(['lead:id,contact_name', 'brand:id,domain,company_name'])
            ->whereIn('status', ['confirmed', 'scheduled', 'active'])
            ->whereBetween('slot_start', [$start, $end]);

        if ($user->role === 'pm') {
            $ids = app(\App\Services\Authorization\PmAuthorizationService::class)->assignedBrandIds($user);
            $q->whereIn('brand_id', $ids->isEmpty() ? [-1] : $ids);
        }

        return $q->limit(200)->get()->map(fn (Booking $b) => [
            'type' => 'booking',
            'id' => $b->id,
            'title' => 'Booking — '.($b->lead?->contact_name ?? '#'.$b->lead_id),
            'date' => Carbon::parse($b->slot_start)->format('Y-m-d'),
            'time' => Carbon::parse($b->slot_start)->format('H:i'),
            'status' => $b->status,
            'brand_id' => $b->brand_id,
            'lead_id' => $b->lead_id,
            'url' => $b->lead_id ? '/leads/'.$b->lead_id : '/availability',
            'color' => 'teal',
        ]);
    }
}
