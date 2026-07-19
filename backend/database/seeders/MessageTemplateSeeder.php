<?php

namespace Database\Seeders;

use App\Models\MessageTemplate;
use Illuminate\Database\Seeder;

class MessageTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $templates = [
            [
                'event_key' => 'site_visit_customer',
                'label' => 'Site visit confirmed (customer)',
                'channel' => 'sms',
                'variables' => ['customer_name', 'company_name', 'visit_date', 'visit_time', 'address', 'portal_url'],
                'body' => 'Hi {{customer_name}}, your site visit with {{company_name}} is confirmed for {{visit_date}} at {{visit_time}}. Address: {{address}}. View details: {{portal_url}}',
            ],
            [
                'event_key' => 'site_visit_contractor',
                'label' => 'Site visit assigned (contractor)',
                'channel' => 'sms',
                'variables' => ['contractor_name', 'customer_name', 'address', 'visit_date', 'visit_time', 'contractor_url'],
                'body' => 'Hi {{contractor_name}}, you have a site visit assigned: {{customer_name}}, {{address}} on {{visit_date}} at {{visit_time}}. View: {{contractor_url}}',
            ],
            [
                'event_key' => 'quote_sent',
                'label' => 'Quote sent (customer)',
                'channel' => 'sms',
                'variables' => ['customer_total', 'portal_url'],
                'body' => 'ServiceOP: Your quote is ready. Total: ${{customer_total}}. Review and respond here: {{portal_url}}',
            ],
            [
                'event_key' => 'job_complete_pending_approval',
                'label' => 'Job complete — customer review',
                'channel' => 'sms',
                'variables' => ['customer_name', 'address', 'portal_url'],
                'body' => 'Hi {{customer_name}}, work at {{address}} is marked complete. Please review and accept here: {{portal_url}}',
            ],
            [
                'event_key' => 'revision_requested',
                'label' => 'Revision requested',
                'channel' => 'sms',
                'variables' => ['address', 'description'],
                'body' => 'ServiceOP: Customer requested a revision for {{address}}: {{description}}',
            ],
            [
                'event_key' => 'progress_update_customer',
                'label' => 'Progress update (customer)',
                'channel' => 'sms',
                'variables' => ['customer_name', 'address', 'portal_url'],
                'body' => 'Hi {{customer_name}}, there is a new progress update on your project at {{address}}. View photos and notes: {{portal_url}}',
            ],
            [
                'event_key' => 'pm_contact_reminder',
                'label' => 'PM contact overdue reminder',
                'channel' => 'sms',
                'variables' => ['pm_name', 'lead_name', 'lead_id'],
                'body' => 'Hi {{pm_name}}, reminder: contact {{lead_name}} (lead #{{lead_id}}) — overdue.',
            ],
            [
                'event_key' => 'pm_contact_escalation_owner',
                'label' => 'PM contact escalation (owner)',
                'channel' => 'sms',
                'variables' => ['pm_name', 'lead_name', 'lead_id'],
                'body' => 'ServiceOP escalation: PM has not contacted lead #{{lead_id}} ({{lead_name}}). Please follow up.',
            ],
            [
                'event_key' => 'contractor_pricing_reminder_pm',
                'label' => 'Contractor pricing overdue (PM)',
                'channel' => 'sms',
                'variables' => ['lead_name', 'lead_id'],
                'body' => 'ServiceOP: Contractor pricing still outstanding for {{lead_name}} (lead #{{lead_id}}).',
            ],
            [
                'event_key' => 'pm_intro_customer',
                'label' => 'PM intro draft fallback',
                'channel' => 'sms',
                'variables' => ['customer_name', 'pm_name'],
                'body' => 'Hi {{customer_name}}, this is {{pm_name}} from ServiceOP following up on your project inquiry. When is a good time to chat?',
            ],
        ];

        foreach ($templates as $tpl) {
            MessageTemplate::updateOrCreate(
                ['event_key' => $tpl['event_key']],
                $tpl
            );
        }
    }
}
