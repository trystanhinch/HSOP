<?php

namespace App\Services\LeadIntake;

use App\Models\AiActionLog;
use App\Models\Lead;
use App\Models\Setting;
use App\Models\User;
use App\Services\AiActionAuthorizer;
use App\Services\EmailService;
use App\Services\SmsMessageTemplates;
use App\Services\SmsService;
use Illuminate\Support\Str;

class LeadIntakeMessagingService
{
    public function __construct(
        private AiActionAuthorizer $authorizer,
        private EmailService $emailService,
        private SmsService $smsService,
    ) {}

    /**
     * @return array{customer: array<string, mixed>, pm: array<string, mixed>}
     */
    public function handle(Lead $lead, ParsedLeadEmail $parsed, ?string $aiSummary, bool $pipelineEnabled = true): array
    {
        if (! $pipelineEnabled) {
            return [
                'customer' => ['skipped' => true, 'reason' => 'kill_switch'],
                'pm' => ['skipped' => true, 'reason' => 'kill_switch'],
            ];
        }

        $aiUser = User::aiSuperAdmin();
        $mode = $this->authorizer->getModuleMode('lead_intake');
        $autoSend = in_array($mode, ['assisted', 'autopilot'], true);

        $companyName = $lead->companySource?->company_name
            ?? SmsMessageTemplates::companyName();

        $firstName = $parsed->firstName ?: Str::before($lead->contact_name ?? 'there', ' ');

        $customerBody = "Hi {$firstName}, thanks for contacting {$companyName}. We received your request for "
            .($parsed->serviceRequested ?: 'your project')
            .'. A project manager will review it and contact you shortly.';

        $pm = $lead->assignedPm;
        $pmBody = "New lead assigned: {$lead->contact_name}\n"
            .'Service: '.($lead->service_category ?: $parsed->serviceRequested ?: '—')."\n"
            .'Scope summary: '.($aiSummary ?: '—')."\n"
            .'Source: '.($lead->companySource?->company_name ?? $lead->source ?? '—')."\n"
            .'Next action: Contact customer or confirm scheduled call.';

        $customerResult = $this->dispatchMessage(
            actionKey: 'send_customer_message',
            triggerEvent: 'lead_intake',
            recipient: $lead->email ?: $lead->phone,
            message: $customerBody,
            lead: $lead,
            aiUser: $aiUser,
            autoSend: $autoSend,
            channel: 'customer',
            parsed: $parsed,
            aiSummary: $aiSummary,
        );

        $pmResult = ['skipped' => true, 'reason' => 'no_pm'];
        if ($pm) {
            $pmResult = $this->dispatchMessage(
                actionKey: 'escalate_to_pm',
                triggerEvent: 'lead_intake',
                recipient: $pm->email ?: $pm->phone,
                message: $pmBody,
                lead: $lead,
                aiUser: $aiUser,
                autoSend: $autoSend,
                channel: 'pm',
                parsed: $parsed,
                aiSummary: $aiSummary,
                pmUser: $pm,
            );
        }

        return [
            'customer' => $customerResult,
            'pm' => $pmResult,
            'mode' => $mode,
            'auto_send' => $autoSend,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function dispatchMessage(
        string $actionKey,
        string $triggerEvent,
        ?string $recipient,
        string $message,
        Lead $lead,
        ?User $aiUser,
        bool $autoSend,
        string $channel,
        ParsedLeadEmail $parsed,
        ?string $aiSummary,
        ?User $pmUser = null,
    ): array {
        $requiresApproval = ! $autoSend;
        $sent = false;
        $sendResults = [];

        if ($autoSend && $recipient) {
            if ($channel === 'customer') {
                if ($lead->phone) {
                    $sendResults['sms'] = $this->smsService->send(
                        $lead->phone,
                        $message,
                        $triggerEvent,
                        $lead->customer_id,
                    );
                }
                if ($lead->email) {
                    $sendResults['email'] = $this->emailService->send(
                        $lead->email,
                        'We received your request',
                        'emails.notification',
                        [
                            'heading' => 'Thank you for contacting us',
                            'body' => $message,
                        ],
                        $triggerEvent,
                        $lead->customer_id,
                    );
                }
            } elseif ($pmUser) {
                if ($pmUser->phone) {
                    $sendResults['sms'] = $this->smsService->send(
                        $pmUser->phone,
                        $message,
                        $triggerEvent,
                        $pmUser->id,
                    );
                }
                if ($pmUser->email) {
                    $sendResults['email'] = $this->emailService->send(
                        $pmUser->email,
                        'New lead assigned: '.$lead->contact_name,
                        'emails.notification',
                        [
                            'heading' => 'New lead assigned',
                            'body' => $message,
                        ],
                        $triggerEvent,
                        $pmUser->id,
                    );
                }
            }

            $sent = collect($sendResults)->contains(fn ($r) => ($r['success'] ?? false) === true);
        }

        $log = AiActionLog::create([
            'trigger_event' => $triggerEvent,
            'actor_id' => $aiUser?->id,
            'data_viewed' => [
                'lead_id' => $lead->id,
                'channel' => $channel,
                'parsed' => $parsed->toArray(),
                'ai_summary' => $aiSummary,
                'is_placeholder' => true,
                'mode' => $this->authorizer->getModuleMode('lead_intake'),
            ],
            'decision' => $requiresApproval ? 'draft' : ($sent ? 'sent' : 'attempted'),
            'action_taken' => $actionKey,
            'message_sent' => $message,
            'recipient' => $recipient,
            'status_before' => $lead->status,
            'status_after' => $lead->status,
            'rule_applied' => 'lead_intake_messaging',
            'required_human_approval' => $requiresApproval,
            'error' => $sent || $requiresApproval ? null : 'No channel delivered',
        ]);

        return [
            'id' => $log->id,
            'channel' => $channel,
            'draft' => $requiresApproval,
            'sent' => $sent,
            'recipient' => $recipient,
            'send_results' => $sendResults,
        ];
    }
}
