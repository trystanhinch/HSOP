<?php

namespace App\Services\Workflow;

use App\Models\Setting;

class WorkflowSettings
{
    public function all(): array
    {
        $defaults = config('workflow.thresholds', []);
        $keys = config('workflow.threshold_keys', []);
        $out = [];

        foreach ($defaults as $name => $default) {
            $settingKey = $keys[$name] ?? ('workflow_'.$name);
            $stored = Setting::get($settingKey);
            $out[$name] = $stored !== null ? (float) $stored : (float) $default;
            $out[$name.'_setting_key'] = $settingKey;
        }

        $calc = app(BusinessHoursCalculator::class);
        $out['clock_mode'] = $calc->clockMode();
        $out['business_hours_profile_id'] = Setting::get('workflow_business_hours_profile_id');
        $profile = $calc->resolveProfile();
        $out['timezone'] = $profile->timezone;
        $out['reminder_stages'] = [
            'pm_contact_lead' => ['reminder', 'escalation', 'stop_on_contact'],
            'quote_follow_up' => ['follow_up', 'stop_on_terminal'],
            'contractor_pricing' => ['reminder'],
            'job_missing_update' => ['flag'],
        ];

        return $out;
    }

    public function get(string $name): float
    {
        $defaults = config('workflow.thresholds', []);
        $keys = config('workflow.threshold_keys', []);
        $settingKey = $keys[$name] ?? ('workflow_'.$name);
        $stored = Setting::get($settingKey);

        return $stored !== null ? (float) $stored : (float) ($defaults[$name] ?? 0);
    }

    public function set(string $name, float|int $value): void
    {
        $keys = config('workflow.threshold_keys', []);
        if (! isset($keys[$name])) {
            throw new \InvalidArgumentException("Unknown workflow threshold [{$name}]");
        }
        if ((float) $value <= 0) {
            throw new \InvalidArgumentException("Threshold [{$name}] must be positive");
        }
        Setting::set($keys[$name], (string) $value);
    }

    public function updateMany(array $values): array
    {
        foreach ($values as $name => $value) {
            if (isset(config('workflow.threshold_keys')[$name])) {
                $this->set($name, (float) $value);
            }
        }

        return $this->all();
    }

    public function pmContactDueAt(?int $brandId = null): \Carbon\CarbonInterface
    {
        $calc = app(BusinessHoursCalculator::class);
        $hours = (float) $this->get('pm_contact_lead_hours');
        $profile = $calc->resolveProfile($brandId);

        return $calc->addThresholdHours(now(), $hours, $profile);
    }
}
