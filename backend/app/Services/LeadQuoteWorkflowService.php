<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Job;
use App\Models\Lead;
use App\Models\Quote;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LeadQuoteWorkflowService
{
    public function __construct(
        protected PricingService $pricing,
        protected LeadCustomerResolver $customers,
        protected JobNotificationService $notifications,
        protected PayoutWorkflowService $payouts,
    ) {}

    public function sendQuote(Lead $lead, ?string $scopeOfWork = null, ?string $customerNotes = null, ?string $internalNotes = null): array
    {
        if (! $lead->contractor_price) {
            throw ValidationException::withMessages([
                'contractor_price' => ['Contractor has not submitted a price yet. Please wait for their price before sending a quote.'],
            ]);
        }

        if (! $lead->contact_name) {
            throw ValidationException::withMessages([
                'contact_name' => ['Lead is missing a contact name.'],
            ]);
        }

        if (! $lead->phone && ! $lead->email) {
            throw ValidationException::withMessages([
                'phone' => ['Lead needs at least a phone number or email to send the quote.'],
            ]);
        }

        $customerId = $this->customers->resolveForLead($lead->fresh());
        $lead->refresh();

        $totals = $this->pricing->fromContractorPrice((float) $lead->contractor_price);

        if (! $lead->customer_portal_token) {
            $lead->update(['customer_portal_token' => Str::random(64)]);
            $lead->refresh();
        }

        $quote = Quote::updateOrCreate(
            ['lead_id' => $lead->id, 'job_id' => null],
            [
                'customer_id' => $customerId,
                'company_id' => $lead->company_id,
                'quote_number' => 'QT-'.str_pad((string) (Quote::count() + 1), 4, '0', STR_PAD_LEFT),
                'scope_of_work' => $scopeOfWork ?: $lead->project_description ?: $lead->notes,
                'contractor_base_price' => $totals['contractor_base_price'],
                'customer_price_before_gst' => $totals['customer_subtotal'],
                'subtotal' => $totals['customer_subtotal'],
                'contractor_pct' => $totals['contractor_pct'],
                'pm_pct' => $totals['pm_pct'],
                'company_pct' => $totals['company_pct'],
                'pm_amount' => $totals['pm_amount'],
                'company_amount' => $totals['company_amount'],
                'hsop_markup' => $totals['hsop_markup'],
                'gst_rate' => $totals['gst_rate'],
                'gst' => $totals['gst'],
                'customer_total' => $totals['customer_total'],
                'gst_enabled' => true,
                'customer_notes' => $customerNotes,
                'internal_notes' => $internalNotes,
                'status' => 'sent',
                'customer_token' => Str::random(64),
                'sent_at' => now(),
            ]
        );

        $lead->update(['status' => 'quote_needed']);

        $portalUrl = SmsMessageTemplates::customerPortalUrl($lead->customer_portal_token);

        $this->notifications->quoteSent($quote->fresh(['customer']), $portalUrl);

        AuditLog::create([
            'user_id' => auth()->id(),
            'user_role' => auth()->user()?->role,
            'object_type' => 'lead',
            'object_id' => $lead->id,
            'action_type' => 'quote_sent_from_lead',
            'new_value' => json_encode(['quote_id' => $quote->id, 'total' => $quote->customer_total]),
        ]);

        return [
            'quote' => $quote->fresh(),
            'portal_url' => $portalUrl,
            'sent_via' => [
                'sms' => (bool) $lead->phone,
                'email' => (bool) $lead->email,
            ],
        ];
    }

    public function approveQuote(Quote $quote): Quote
    {
        if (! in_array($quote->status, ['sent', 'viewed'], true)) {
            throw ValidationException::withMessages([
                'status' => ['Quote cannot be approved in current status'],
            ]);
        }

        $quote->update(['status' => 'approved', 'accepted_at' => now()]);

        if ($quote->lead_id && ! $quote->job_id) {
            $this->createJobFromLeadQuote($quote);
        } elseif ($quote->job_id) {
            $quote->job?->update(['status' => 'quote_approved']);
        }

        return $quote->fresh(['job', 'customer', 'lead']);
    }

    public function rejectQuote(Quote $quote, string $reason): Quote
    {
        $quote->update(['status' => 'rejected', 'rejection_reason' => $reason]);

        if ($quote->job_id) {
            $quote->job?->update(['status' => 'waiting_on_customer']);
        }

        return $quote->fresh(['job', 'customer', 'lead']);
    }

    protected function createJobFromLeadQuote(Quote $quote): Job
    {
        $lead = Lead::findOrFail($quote->lead_id);
        $category = str_replace('_', ' ', $lead->service_category ?? 'service');

        $job = Job::create([
            'lead_id' => $lead->id,
            'company_id' => $lead->company_id,
            'customer_id' => $quote->customer_id,
            'pm_id' => $lead->assigned_pm_id,
            'contractor_id' => $lead->assigned_contractor_id ?? $lead->site_visit_contractor_id,
            'service_category' => $lead->service_category,
            'address' => $lead->address,
            'job_title' => $lead->contact_name.' — '.ucwords($category),
            'scope_of_work' => $quote->scope_of_work ?? $lead->project_description ?? $lead->notes,
            'status' => 'quote_approved',
            'contractor_submitted_price' => $lead->contractor_price,
            'contractor_price_status' => 'approved',
            'contractor_price_submitted_at' => $lead->contractor_price_submitted_at,
            'split_contractor_pct' => $quote->contractor_pct ?? 80,
            'split_pm_pct' => $quote->pm_pct ?? 10,
            'split_company_pct' => $quote->company_pct ?? 10,
        ]);

        $quote->update(['job_id' => $job->id]);
        $lead->update(['status' => 'converted']);

        AuditLog::create([
            'user_id' => auth()->id(),
            'user_role' => auth()->user()?->role ?? 'customer',
            'object_type' => 'job',
            'object_id' => $job->id,
            'action_type' => 'job_created_on_quote_approval',
            'new_value' => json_encode(['lead_id' => $lead->id, 'quote_id' => $quote->id]),
        ]);

        return $job;
    }

    public function findQuoteForLead(Lead $lead): ?Quote
    {
        return Quote::forLead($lead);
    }
}
