<?php

namespace App\Services\Calendar;

use App\Models\BookingHold;
use App\Models\Job;
use App\Models\SiteVisit;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * A-31 — Conflict detection for accepted assignments + active holds.
 */
class CalendarConflictService
{
    /**
     * @param  Collection<int, array<string, mixed>>  $events
     * @return list<array<string, mixed>>
     */
    public function detectInEvents(Collection $events): array
    {
        $byContractor = [];
        foreach ($events as $event) {
            $cid = $event['contractor_id'] ?? null;
            if (! $cid) {
                continue;
            }
            if (! in_array($event['type'] ?? '', ['site_visit', 'job', 'contractor_assignment'], true)) {
                continue;
            }
            // Only accepted / active site visits and scheduled jobs count.
            if (($event['type'] ?? '') === 'site_visit'
                && in_array($event['status'] ?? '', ['declined', 'cancelled'], true)) {
                continue;
            }
            $byContractor[$cid][] = $event;
        }

        $conflicts = [];
        foreach ($byContractor as $cid => $list) {
            $n = count($list);
            for ($i = 0; $i < $n; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    if ($this->sameDayOverlap($list[$i], $list[$j])) {
                        $conflicts[] = [
                            'type' => 'double_book',
                            'contractor_id' => $cid,
                            'a' => ['type' => $list[$i]['type'], 'id' => $list[$i]['id'], 'title' => $list[$i]['title']],
                            'b' => ['type' => $list[$j]['type'], 'id' => $list[$j]['id'], 'title' => $list[$j]['title']],
                            'date' => $list[$i]['date'] ?? null,
                            'message' => 'Contractor already has an accepted assignment overlapping this time.',
                        ];
                    }
                }
            }
        }

        return $conflicts;
    }

    /**
     * Check proposing a site visit / job assignment for a contractor.
     *
     * @return array{conflict: bool, conflicts: list<array<string, mixed>>, message: ?string}
     */
    public function checkContractorSlot(
        int $contractorId,
        string $date,
        ?string $time = null,
        ?string $excludeType = null,
        ?int $excludeId = null,
        int $durationMinutes = 60,
        int $travelBufferMinutes = 0,
    ): array {
        $start = Carbon::parse($date.($time ? ' '.$time : ' 09:00'));
        $end = $start->copy()->addMinutes($durationMinutes + $travelBufferMinutes);
        $windowStart = $start->copy()->subMinutes($travelBufferMinutes);

        $conflicts = [];

        $visits = SiteVisit::query()
            ->where('contractor_id', $contractorId)
            ->whereDate('visit_date', $date)
            ->whereNotIn('status', ['cancelled', 'declined'])
            ->when($excludeType === 'site_visit' && $excludeId, fn ($q) => $q->where('id', '!=', $excludeId))
            ->get();

        foreach ($visits as $sv) {
            // Accepted or pending counts as blocking once assigned.
            if (! in_array($sv->status, ['accepted', 'scheduled', 'completed', 'pending', 'assigned'], true)
                && $sv->accepted_at === null) {
                // Still block any non-cancelled assignment on the same day/time.
            }
            $svStart = Carbon::parse($sv->visit_date->format('Y-m-d').' '.substr((string) ($sv->visit_time ?: '09:00'), 0, 5));
            $svEnd = $svStart->copy()->addMinutes(60);
            if ($windowStart->lt($svEnd) && $end->gt($svStart)) {
                $conflicts[] = [
                    'type' => 'site_visit',
                    'id' => $sv->id,
                    'title' => 'Site visit #'.$sv->id,
                    'status' => $sv->status,
                    'accepted' => $sv->accepted_at !== null
                        || $sv->status === 'accepted'
                        || in_array((string) ($sv->assignment_state ?? ''), ['accepted', 'confirmed'], true),
                ];
            }
        }

        $jobs = Job::query()
            ->where('contractor_id', $contractorId)
            ->whereDate('scheduled_start_date', $date)
            ->whereNotIn('status', ['cancelled', 'declined'])
            ->when($excludeType === 'job' && $excludeId, fn ($q) => $q->where('id', '!=', $excludeId))
            ->get();

        foreach ($jobs as $job) {
            $jStart = Carbon::parse(
                $job->scheduled_start_date->format('Y-m-d').' '.substr((string) ($job->scheduled_start_time ?: '09:00'), 0, 5)
            );
            $jEnd = $jStart->copy()->addMinutes(120);
            if ($windowStart->lt($jEnd) && $end->gt($jStart)) {
                $conflicts[] = [
                    'type' => 'job',
                    'id' => $job->id,
                    'title' => $job->job_title ?? ('Job #'.$job->id),
                    'status' => $job->status,
                    'accepted' => true,
                ];
            }
        }

        $holds = BookingHold::query()
            ->where('resource_key', 'contractor:'.$contractorId)
            ->where('status', 'held')
            ->where('held_until', '>', now())
            ->whereDate('slot_start', $date)
            ->get();

        foreach ($holds as $hold) {
            $hStart = Carbon::parse($hold->slot_start);
            $hEnd = Carbon::parse($hold->slot_end ?? $hStart->copy()->addHour());
            if ($windowStart->lt($hEnd) && $end->gt($hStart)) {
                $conflicts[] = [
                    'type' => 'booking_hold',
                    'id' => $hold->id,
                    'title' => 'Active hold',
                    'status' => $hold->status,
                    'accepted' => true,
                ];
            }
        }

        $hasAccepted = collect($conflicts)->contains(fn ($c) => ! empty($c['accepted']) || in_array($c['type'], ['job', 'booking_hold'], true));

        return [
            'conflict' => $conflicts !== [],
            'conflicts' => $conflicts,
            'message' => $conflicts === []
                ? null
                : ($hasAccepted
                    ? 'Conflict: contractor already has an accepted assignment or active hold overlapping this slot.'
                    : 'Conflict: contractor already has an overlapping assignment.'),
        ];
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    private function sameDayOverlap(array $a, array $b): bool
    {
        if (($a['date'] ?? null) !== ($b['date'] ?? null)) {
            return false;
        }
        $aTime = $a['time'] ?? '09:00';
        $bTime = $b['time'] ?? '09:00';
        $aStart = Carbon::parse(($a['date'] ?? '').' '.$aTime);
        $bStart = Carbon::parse(($b['date'] ?? '').' '.$bTime);
        $aEnd = $aStart->copy()->addMinutes(60 + (int) ($a['travel_buffer_minutes'] ?? 0));
        $bEnd = $bStart->copy()->addMinutes(60 + (int) ($b['travel_buffer_minutes'] ?? 0));

        return $aStart->lt($bEnd) && $bStart->lt($aEnd);
    }
}
