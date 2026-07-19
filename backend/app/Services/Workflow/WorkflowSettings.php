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

    public function pmContactDueAt(): \Carbon\CarbonInterface
    {
        return now()->addHours((int) $this->get('pm_contact_lead_hours'));
    }
}
