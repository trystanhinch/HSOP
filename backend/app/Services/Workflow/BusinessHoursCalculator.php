<?php

namespace App\Services\Workflow;

use App\Models\BusinessHoursProfile;
use App\Models\Setting;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * A-15 — Timezone-aware business-hours elapsed time for workflow thresholds.
 *
 * Wall-clock mode remains available; business mode skips nights/weekends/holidays.
 */
class BusinessHoursCalculator
{
    public function resolveProfile(?int $brandId = null): BusinessHoursProfile
    {
        if ($brandId) {
            $brandProfile = BusinessHoursProfile::query()
                ->where('brand_id', $brandId)
                ->orderByDesc('is_default')
                ->first();
            if ($brandProfile) {
                return $brandProfile;
            }
        }

        $settingId = Setting::get('workflow_business_hours_profile_id');
        if ($settingId) {
            $fromSetting = BusinessHoursProfile::query()->find((int) $settingId);
            if ($fromSetting) {
                return $fromSetting;
            }
        }

        $default = BusinessHoursProfile::query()->where('is_default', true)->first();
        if ($default) {
            return $default;
        }

        // Synthetic in-memory default (not persisted) — Mon–Fri 9–5 Vancouver
        $profile = new BusinessHoursProfile([
            'name' => 'Synthetic Default',
            'timezone' => config('booking.timezone', config('booking.default_timezone', 'America/Vancouver')),
            'weekly_hours' => BusinessHoursProfile::defaultWeeklyHours(),
            'holidays' => [],
            'is_default' => true,
        ]);

        return $profile;
    }

    public function clockMode(): string
    {
        $mode = Setting::get('workflow_clock_mode');

        return in_array($mode, ['wall', 'business'], true) ? $mode : 'business';
    }

    /**
     * Add N hours respecting business hours when clock_mode=business.
     */
    public function addThresholdHours(
        CarbonInterface|string $from,
        float $hours,
        ?BusinessHoursProfile $profile = null,
        ?string $clockMode = null,
    ): Carbon {
        $mode = $clockMode ?: $this->clockMode();
        $profile ??= $this->resolveProfile();
        $tz = $profile->timezone ?: config('app.timezone', 'UTC');
        $cursor = Carbon::parse($from)->timezone($tz);

        if ($mode === 'wall' || $hours <= 0) {
            return $cursor->copy()->addSeconds((int) round($hours * 3600));
        }

        $remainingSeconds = (int) round($hours * 3600);
        $guard = 0;
        while ($remainingSeconds > 0 && $guard < 20000) {
            $guard++;
            $cursor = $this->snapToOpen($cursor, $profile);
            $windowEnd = $this->currentWindowEnd($cursor, $profile);
            if (! $windowEnd) {
                $cursor = $this->nextOpenStart($cursor->copy()->addMinute(), $profile);
                continue;
            }
            $available = $cursor->diffInSeconds($windowEnd, false);
            if ($available <= 0) {
                $cursor = $this->nextOpenStart($cursor->copy()->addMinute(), $profile);
                continue;
            }
            if ($remainingSeconds <= $available) {
                return $cursor->copy()->addSeconds($remainingSeconds);
            }
            $remainingSeconds -= $available;
            $cursor = $this->nextOpenStart($windowEnd->copy()->addMinute(), $profile);
        }

        return $cursor;
    }

    /**
     * True if $due is in the past under the active clock (for overdue checks).
     * due_at is already absolute; comparison is wall-clock against now.
     */
    public function isPastDue(CarbonInterface $due): bool
    {
        return now()->greaterThan($due);
    }

    /**
     * @return list<array{at: string, label: string, actor: string}>
     */
    public function previewTimeline(
        float $contactHours,
        float $escalationHours,
        ?BusinessHoursProfile $profile = null,
        ?string $clockMode = null,
        ?CarbonInterface $from = null,
    ): array {
        $profile ??= $this->resolveProfile();
        $mode = $clockMode ?: $this->clockMode();
        $start = Carbon::parse($from ?? now())->timezone($profile->timezone ?: 'America/Vancouver');

        $reminderAt = $this->addThresholdHours($start, $contactHours, $profile, $mode);
        $escalationAt = $this->addThresholdHours($reminderAt, $escalationHours, $profile, $mode);

        return [
            [
                'at' => $start->toIso8601String(),
                'label' => 'Lead assigned / clock starts',
                'actor' => 'system',
                'timezone' => $profile->timezone,
            ],
            [
                'at' => $reminderAt->toIso8601String(),
                'label' => "PM contact reminder due ({$contactHours}h {$mode})",
                'actor' => 'pm',
                'timezone' => $profile->timezone,
            ],
            [
                'at' => $escalationAt->toIso8601String(),
                'label' => "Owner escalation ({$escalationHours}h after reminder)",
                'actor' => 'owner',
                'timezone' => $profile->timezone,
            ],
        ];
    }

    private function snapToOpen(Carbon $cursor, BusinessHoursProfile $profile): Carbon
    {
        if ($this->isOpenAt($cursor, $profile)) {
            return $cursor;
        }

        return $this->nextOpenStart($cursor, $profile);
    }

    private function isHoliday(Carbon $date, BusinessHoursProfile $profile): bool
    {
        $holidays = $profile->holidays ?? [];
        $ymd = $date->format('Y-m-d');

        return in_array($ymd, $holidays, true);
    }

    private function isOpenAt(Carbon $cursor, BusinessHoursProfile $profile): bool
    {
        if ($this->isHoliday($cursor, $profile)) {
            return false;
        }
        $hours = $profile->weekly_hours ?: BusinessHoursProfile::defaultWeeklyHours();
        $dow = (string) $cursor->dayOfWeekIso; // 1=Mon
        $windows = $hours[$dow] ?? [];
        if ($windows === []) {
            return false;
        }
        $t = $cursor->format('H:i');
        foreach ($windows as $window) {
            [$start, $end] = $window;
            if ($t >= $start && $t < $end) {
                return true;
            }
        }

        return false;
    }

    private function currentWindowEnd(Carbon $cursor, BusinessHoursProfile $profile): ?Carbon
    {
        $hours = $profile->weekly_hours ?: BusinessHoursProfile::defaultWeeklyHours();
        $dow = (string) $cursor->dayOfWeekIso;
        $windows = $hours[$dow] ?? [];
        $t = $cursor->format('H:i');
        foreach ($windows as $window) {
            [$start, $end] = $window;
            if ($t >= $start && $t < $end) {
                [$h, $m] = array_map('intval', explode(':', $end));

                return $cursor->copy()->setTime($h, $m, 0);
            }
        }

        return null;
    }

    private function nextOpenStart(Carbon $from, BusinessHoursProfile $profile): Carbon
    {
        $cursor = $from->copy();
        $hours = $profile->weekly_hours ?: BusinessHoursProfile::defaultWeeklyHours();
        for ($i = 0; $i < 400; $i++) {
            if ($this->isHoliday($cursor, $profile)) {
                $cursor->addDay()->startOfDay();
                continue;
            }
            $dow = (string) $cursor->dayOfWeekIso;
            $windows = $hours[$dow] ?? [];
            foreach ($windows as $window) {
                [$start, $end] = $window;
                [$sh, $sm] = array_map('intval', explode(':', $start));
                $startAt = $cursor->copy()->setTime($sh, $sm, 0);
                if ($cursor->lte($startAt) && $start < $end) {
                    return $startAt;
                }
                if ($cursor->format('H:i') < $end && $cursor->format('H:i') >= $start) {
                    return $cursor->copy();
                }
            }
            $cursor->addDay()->startOfDay();
        }

        return $from->copy()->addDay();
    }
}
