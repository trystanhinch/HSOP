<?php

namespace App\Services\ReviewGateway;

use App\Models\Lead;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * GET lead-journey — versioned provenance packet for a lead lifecycle.
 */
class LeadJourneyTool
{
    public const TOOL = 'lead_journey';

    public function __construct(private SensitiveDataGuard $guard) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(int $leadId): array
    {
        $lead = Lead::query()
            ->with([
                'assignedPm:id,name,email,role',
                'siteVisitContractor:id,name,email,role',
                'quotes:id,lead_id,job_id,quote_number,status,customer_total,sent_at,created_at,updated_at',
                'siteVisit:id,lead_id,contractor_id,pm_id,status,assignment_state,visit_date,visit_time,created_at,updated_at',
                'job:id,lead_id,status,service_category,address,contractor_id,pm_id,scheduled_start_date,completed_at,payment_confirmed_at,customer_accepted_completion_at,created_at,updated_at',
                'job.invoice:id,job_id,invoice_number,status,subtotal,gst,amount,amount_paid,payment_date,created_at,updated_at',
                'job.invoice.payments:id,invoice_id,amount,method,status,paid_date,created_at,updated_at',
                'job.payouts:id,job_id,payout_type,status,payout_amount,scheduled_for,paid_date,created_at,updated_at',
                'currentEstimateOutcome:id,lead_id,job_id,is_current,created_at,updated_at',
            ])
            ->find($leadId);

        if (! $lead) {
            throw (new ModelNotFoundException)->setModel(Lead::class, [$leadId]);
        }

        $payload = [
            'tool' => self::TOOL,
            'tool_version' => config('review_gateway.tool_versions.lead_journey', '1.0.0'),
            'lead' => [
                'id' => $lead->id,
                'status' => $lead->status,
                'service_category' => $lead->service_category,
                'contact_name' => $lead->contact_name,
                'phone' => $lead->phone,
                'email' => $lead->email,
                'address' => $lead->address,
                'source' => $lead->source,
                'intake_channel' => $lead->intake_channel,
                'brand_id' => $lead->brand_id,
                'assigned_pm_id' => $lead->assigned_pm_id,
                'assigned_pm' => $lead->assignedPm?->only(['id', 'name', 'email', 'role']),
                'created_at' => optional($lead->created_at)?->toIso8601String(),
                'updated_at' => optional($lead->updated_at)?->toIso8601String(),
            ],
            'site_visit' => $lead->siteVisit ? [
                'id' => $lead->siteVisit->id,
                'status' => $lead->siteVisit->status,
                'assignment_state' => $lead->siteVisit->assignment_state ?? null,
                'visit_date' => $lead->siteVisit->visit_date,
                'visit_time' => $lead->siteVisit->visit_time,
                'contractor_id' => $lead->siteVisit->contractor_id,
                'pm_id' => $lead->siteVisit->pm_id,
                'created_at' => optional($lead->siteVisit->created_at)?->toIso8601String(),
                'updated_at' => optional($lead->siteVisit->updated_at)?->toIso8601String(),
            ] : null,
            'quotes' => $lead->quotes->map(fn ($q) => [
                'id' => $q->id,
                'quote_number' => $q->quote_number,
                'status' => $q->status,
                'customer_total' => $q->customer_total,
                'job_id' => $q->job_id,
                'sent_at' => optional($q->sent_at)?->toIso8601String(),
                'created_at' => optional($q->created_at)?->toIso8601String(),
                'updated_at' => optional($q->updated_at)?->toIso8601String(),
            ])->values()->all(),
            'job' => null,
            'outcome' => $lead->currentEstimateOutcome ? [
                'id' => $lead->currentEstimateOutcome->id,
                'lead_id' => $lead->currentEstimateOutcome->lead_id,
                'job_id' => $lead->currentEstimateOutcome->job_id,
                'is_current' => (bool) $lead->currentEstimateOutcome->is_current,
                'created_at' => optional($lead->currentEstimateOutcome->created_at)?->toIso8601String(),
                'updated_at' => optional($lead->currentEstimateOutcome->updated_at)?->toIso8601String(),
            ] : null,
        ];

        if ($lead->job) {
            $job = $lead->job;
            $payload['job'] = [
                'id' => $job->id,
                'status' => $job->status,
                'service_category' => $job->service_category,
                'address' => $job->address,
                'contractor_id' => $job->contractor_id,
                'pm_id' => $job->pm_id,
                'scheduled_start_date' => $job->scheduled_start_date,
                'completed_at' => optional($job->completed_at)?->toIso8601String(),
                'payment_confirmed_at' => optional($job->payment_confirmed_at)?->toIso8601String(),
                'customer_accepted_completion_at' => optional($job->customer_accepted_completion_at)?->toIso8601String(),
                'created_at' => optional($job->created_at)?->toIso8601String(),
                'updated_at' => optional($job->updated_at)?->toIso8601String(),
                'invoice' => $job->invoice ? [
                    'id' => $job->invoice->id,
                    'invoice_number' => $job->invoice->invoice_number,
                    'status' => $job->invoice->status,
                    'subtotal' => $job->invoice->subtotal,
                    'gst' => $job->invoice->gst,
                    'amount' => $job->invoice->amount,
                    'amount_paid' => $job->invoice->amount_paid,
                    'payment_date' => $job->invoice->payment_date,
                    'created_at' => optional($job->invoice->created_at)?->toIso8601String(),
                    'updated_at' => optional($job->invoice->updated_at)?->toIso8601String(),
                    'payments' => $job->invoice->payments->map(fn ($p) => [
                        'id' => $p->id,
                        'amount' => $p->amount,
                        'method' => $p->method,
                        'status' => $p->status,
                        'paid_date' => $p->paid_date,
                        'created_at' => optional($p->created_at)?->toIso8601String(),
                        'updated_at' => optional($p->updated_at)?->toIso8601String(),
                    ])->values()->all(),
                ] : null,
                'payouts' => $job->payouts->map(fn ($p) => [
                    'id' => $p->id,
                    'payout_type' => $p->payout_type,
                    'status' => $p->status,
                    'payout_amount' => $p->payout_amount,
                    'scheduled_for' => $p->scheduled_for,
                    'paid_date' => $p->paid_date,
                    'created_at' => optional($p->created_at)?->toIso8601String(),
                    'updated_at' => optional($p->updated_at)?->toIso8601String(),
                ])->values()->all(),
            ];
        }

        return $this->guard->scrub($payload);
    }
}
