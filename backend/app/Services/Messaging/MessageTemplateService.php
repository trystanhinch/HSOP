<?php

namespace App\Services\Messaging;

use App\Models\Brand;
use App\Models\MessageTemplate;
use App\Models\MessageTemplateVersion;
use App\Models\User;
use App\Services\BrandResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A-16 — template preview, validation, versioning, SMS segment math.
 */
class MessageTemplateService
{
    public function sampleVars(string $eventKey, ?Brand $brand = null): array
    {
        $brandName = $brand?->company_name
            ?? Brand::where('status', 'active')->value('company_name')
            ?? app(BrandResolver::class)->fallback();

        $base = [
            'company_name' => $brandName,
            'brand_name' => $brandName,
            'customer_name' => 'Alex Customer',
            'contractor_name' => 'Jordan Contractor',
            'pm_name' => 'Sam Project Manager',
            'lead_name' => 'Alex Customer',
            'lead_id' => '42',
            'address' => '123 Sample St, Vancouver',
            'visit_date' => 'Aug 1, 2026',
            'visit_time' => '10:00 AM',
            'portal_url' => 'https://example.com/portal/sample-token',
            'contractor_url' => 'https://example.com/jobs/42',
            'review_url' => 'https://example.com/portal/sample-token/review',
            'customer_total' => '1,250.00',
            'description' => 'Please touch up the ceiling corner.',
        ];

        $tpl = MessageTemplate::query()->where('event_key', $eventKey)->first();
        if ($tpl && is_array($tpl->variables)) {
            foreach ($tpl->variables as $key) {
                if (! array_key_exists($key, $base)) {
                    $base[$key] = '['.$key.']';
                }
            }
        }

        return $base;
    }

    /**
     * @param  array<string, mixed>  $vars
     * @return array{rendered: string, unresolved: list<string>, char_count: int, sms_segments: int, sms_segment_warning: bool|null}
     */
    public function preview(string $body, array $vars, string $channel = 'sms'): array
    {
        $rendered = $body;
        foreach ($vars as $key => $value) {
            $rendered = str_replace('{{'.$key.'}}', (string) $value, $rendered);
        }

        preg_match_all('/\{\{([a-z0-9_]+)\}\}/i', $rendered, $matches);
        $unresolved = array_values(array_unique($matches[1] ?? []));

        $charCount = mb_strlen($rendered);
        $segments = $this->smsSegmentCount($rendered);
        $warning = null;
        if (in_array($channel, ['sms', 'both'], true)) {
            $warning = $segments > 1;
        }

        return [
            'rendered' => $rendered,
            'unresolved' => $unresolved,
            'char_count' => $charCount,
            'sms_segments' => $segments,
            'sms_segment_warning' => $warning,
            'sms_segment_note' => $segments > 1
                ? "This SMS is {$segments} segments (~{$charCount} chars). Multi-segment messages cost more and may split on some carriers."
                : null,
        ];
    }

    public function smsSegmentCount(string $body): int
    {
        $len = mb_strlen($body);
        if ($len === 0) {
            return 0;
        }
        // GSM-7 single segment 160; concatenated 153. UCS-2 rare — treat all as GSM-7 for warning purposes.
        if ($len <= 160) {
            return 1;
        }

        return (int) ceil($len / 153);
    }

    /**
     * Block save when sample render would leave literal {{variables}}.
     *
     * @param  array<string, mixed>|null  $sampleOverride
     */
    public function assertResolvable(MessageTemplate $template, string $body, ?array $sampleOverride = null): void
    {
        $vars = $sampleOverride ?? $this->sampleVars($template->event_key);
        $preview = $this->preview($body, $vars, $template->channel ?? 'sms');
        if ($preview['unresolved'] !== []) {
            throw ValidationException::withMessages([
                'body' => 'Template would send unresolved placeholders: {{'
                    .implode('}}, {{', $preview['unresolved']).'}}. Add sample-compatible variables or remove them.',
                'unresolved' => $preview['unresolved'],
            ]);
        }
    }

    public function saveVersion(MessageTemplate $template, User $actor, ?string $reason = null): MessageTemplateVersion
    {
        $next = (int) MessageTemplateVersion::query()
            ->where('message_template_id', $template->id)
            ->max('version') + 1;

        return MessageTemplateVersion::create([
            'message_template_id' => $template->id,
            'version' => max(1, $next),
            'label' => $template->label,
            'body' => $template->body,
            'channel' => $template->channel,
            'variables' => $template->variables,
            'is_active' => $template->is_active,
            'changed_by' => $actor->id,
            'change_reason' => $reason,
        ]);
    }

    public function restore(MessageTemplate $template, MessageTemplateVersion $version, User $actor): MessageTemplate
    {
        if ((int) $version->message_template_id !== (int) $template->id) {
            throw ValidationException::withMessages(['version' => 'Version does not belong to this template.']);
        }

        return DB::transaction(function () use ($template, $version, $actor) {
            $this->saveVersion($template, $actor, 'pre_restore_snapshot');
            $template->update([
                'label' => $version->label ?? $template->label,
                'body' => $version->body,
                'channel' => $version->channel ?? $template->channel,
                'variables' => $version->variables ?? $template->variables,
                'is_active' => $version->is_active,
            ]);
            $this->saveVersion($template->fresh(), $actor, 'restored_from_v'.$version->version);

            return $template->fresh();
        });
    }
}
