<?php

namespace App\Services\Contractors;

use App\Models\Contractor;
use Carbon\Carbon;

/**
 * CT-09 — contractor working hours / blackouts / pause.
 * Used as matching INPUT only — never auto-cancels accepted work.
 */
class ContractorAvailabilityService
{
    /**
     * @return array<string, mixed>
     */
    public function present(Contractor $contractor): array
    {
        return [
            'working_hours' => $contractor->working_hours ?: $this->defaultWorkingHours(),
            'blackout_ranges' => $contractor->blackout_ranges ?: [],
            'min_notice_hours' => (int) ($contractor->min_notice_hours ?? 24),
            'daily_capacity' => (int) ($contractor->daily_capacity ?? 3),
            'availability_paused' => (bool) $contractor->availability_paused,
            'availability_paused_until' => $contractor->availability_paused_until
                ? (string) $contractor->availability_paused_until
                : null,
            'availability_notes' => $contractor->availability_notes,
            'services' => $contractor->services ?: [],
            'cities' => $contractor->cities ?: [],
            'note' => 'Changing availability does not cancel accepted site visits or jobs. It only affects new offers.',
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Contractor $contractor, array $data): Contractor
    {
        $contractor->fill([
            'working_hours' => $data['working_hours'] ?? $contractor->working_hours,
            'blackout_ranges' => $data['blackout_ranges'] ?? $contractor->blackout_ranges,
            'min_notice_hours' => $data['min_notice_hours'] ?? $contractor->min_notice_hours,
            'daily_capacity' => $data['daily_capacity'] ?? $contractor->daily_capacity,
            'availability_paused' => array_key_exists('availability_paused', $data)
                ? (bool) $data['availability_paused']
                : $contractor->availability_paused,
            'availability_paused_until' => array_key_exists('availability_paused_until', $data)
                ? $data['availability_paused_until']
                : $contractor->availability_paused_until,
            'availability_notes' => array_key_exists('availability_notes', $data)
                ? $data['availability_notes']
                : $contractor->availability_notes,
            'services' => $data['services'] ?? $contractor->services,
            'cities' => $data['cities'] ?? $contractor->cities,
        ]);
        $contractor->save();

        return $contractor->fresh();
    }

    /**
     * Can this contractor receive a NEW offer for a given visit datetime?
     * Does not affect already-accepted work.
     */
    public function canReceiveNewOffer(Contractor $contractor, Carbon|string $visitAt): bool
    {
        if ($contractor->availability_paused) {
            $until = $contractor->availability_paused_until
                ? Carbon::parse((string) $contractor->availability_paused_until)->endOfDay()
                : null;
            if ($until === null || now()->lte($until)) {
                return false;
            }
        }

        $when = $visitAt instanceof Carbon ? $visitAt->copy() : Carbon::parse($visitAt);
        $hoursNotice = now()->diffInHours($when, false);
        $minNotice = (int) ($contractor->min_notice_hours ?? 24);
        if ($hoursNotice >= 0 && $hoursNotice < $minNotice) {
            return false;
        }

        foreach ($contractor->blackout_ranges ?: [] as $range) {
            $start = isset($range['start']) ? Carbon::parse($range['start'])->startOfDay() : null;
            $end = isset($range['end']) ? Carbon::parse($range['end'])->endOfDay() : null;
            if ($start && $end && $when->gte($start) && $when->lte($end)) {
                return false;
            }
        }

        $hours = $contractor->working_hours ?: $this->defaultWorkingHours();
        $dayKey = strtolower($when->format('D')); // mon, tue...
        $map = [
            'mon' => 'mon', 'tue' => 'tue', 'wed' => 'wed', 'thu' => 'thu',
            'fri' => 'fri', 'sat' => 'sat', 'sun' => 'sun',
        ];
        $key = $map[$dayKey] ?? $dayKey;
        $day = $hours[$key] ?? null;
        if (is_array($day) && (($day['closed'] ?? false) === true)) {
            return false;
        }
        if (is_array($day) && ! empty($day['start']) && ! empty($day['end'])) {
            $t = $when->format('H:i');
            if ($t < $day['start'] || $t > $day['end']) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, array{start: string, end: string, closed?: bool}>
     */
    public function defaultWorkingHours(): array
    {
        $open = ['start' => '08:00', 'end' => '17:00', 'closed' => false];
        $closed = ['start' => '', 'end' => '', 'closed' => true];

        return [
            'mon' => $open,
            'tue' => $open,
            'wed' => $open,
            'thu' => $open,
            'fri' => $open,
            'sat' => $closed,
            'sun' => $closed,
        ];
    }
}
