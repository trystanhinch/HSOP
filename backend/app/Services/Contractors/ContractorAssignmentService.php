<?php

namespace App\Services\Contractors;

use App\Models\Job;
use App\Models\Lead;
use App\Models\SiteVisit;
use App\Models\User;
use Illuminate\Support\Collection;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Audit CT-01 — single definition of "this contractor's current work."
 *
 * Covers lead-stage site visits / assignments AND job-stage work so Dashboard,
 * My Leads, Schedule, and Jobs never disagree about whether an assignment exists.
 */
class ContractorAssignmentService
{
    /**
     * Unified Jobs-page list: open lead-stage assignments + jobs.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function workItemsFor(User $contractor): Collection
    {
        $leads = $this->serializeOpenLeadAssignments($contractor);
        $jobs = $this->serializeJobs($contractor);

        return $leads->concat($jobs)->values();
    }

    /**
     * Open (pre-job) leads assigned to this contractor — My Leads source of truth.
     *
     * @return Collection<int, Lead>
     */
    public function openLeadsQuery(User $contractor)
    {
        return Lead::query()
            ->where(function ($q) use ($contractor) {
                $q->where('assigned_contractor_id', $contractor->id)
                    ->orWhere('site_visit_contractor_id', $contractor->id);
            })
            ->whereNotIn('status', ['converted', 'lost'])
            ->whereDoesntHave('job');
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function serializeOpenLeadAssignments(User $contractor): Collection
    {
        return $this->openLeadsQuery($contractor)
            ->with([
                'assignedPm:id,name,email,phone',
                'siteVisit',
                'customer:id,name',
            ])
            ->latest()
            ->get()
            ->map(fn (Lead $lead) => $this->serializeLeadAssignment($lead, $contractor));
    }

    /**
     * Upcoming/active site visits for dashboard cards (same deep-link as Jobs/My Leads).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function upcomingSiteVisitsFor(User $contractor): Collection
    {
        return $this->openLeadsQuery($contractor)
            ->where(function ($q) {
                $q->whereNotNull('site_visit_date')
                    ->orWhereHas('siteVisit', fn ($sv) => $sv->where('status', '!=', 'cancelled'));
            })
            ->with(['assignedPm:id,name,email,phone', 'siteVisit', 'customer:id,name'])
            ->orderBy('site_visit_date')
            ->get()
            ->map(function (Lead $lead) use ($contractor) {
                $row = $this->serializeLeadAssignment($lead, $contractor);

                return [
                    'type' => 'site_visit',
                    'id' => $lead->siteVisit?->id ?? $lead->id,
                    'lead_id' => $lead->id,
                    'address' => $row['address'],
                    'customer_name' => $row['customer']['name'] ?? $lead->contact_name,
                    'service' => $lead->service_category,
                    'description' => $lead->project_description ?? $lead->notes,
                    'visit_date' => $row['visit_date'],
                    'visit_time' => $row['visit_time'],
                    'status' => $row['status'],
                    'assignment_state' => $row['assignment_state'] ?? null,
                    'assignment_state_label' => $row['assignment_state_label'] ?? null,
                    'is_confirmed' => $row['is_confirmed'] ?? false,
                    'respond_by' => $row['respond_by'] ?? null,
                    'pm' => $row['pm'],
                    'url' => $row['url'],
                ];
            })
            ->values();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function serializeJobs(User $contractor): Collection
    {
        return Job::query()
            ->with(['customer:id,name', 'pm:id,name,email,phone', 'company:id,name', 'lead:id'])
            ->where('contractor_id', $contractor->id)
            ->latest()
            ->get()
            ->map(fn (Job $job) => $this->serializeJobAssignment($job));
    }

    /**
     * Canonical lead-stage assignment payload (Jobs / My Leads / detail deep-link).
     *
     * @return array<string, mixed>
     */
    public function serializeLeadAssignment(Lead $lead, User $contractor): array
    {
        $sv = $lead->relationLoaded('siteVisit') ? $lead->siteVisit : $lead->siteVisit()->first();
        $visitDate = $sv?->visit_date ?? $lead->site_visit_date;
        $visitTime = $sv?->visit_time ?? $lead->site_visit_time;
        $assignmentType = (int) $lead->assigned_contractor_id === (int) $contractor->id
            ? 'assigned'
            : 'site_visit';

        $hasVisit = $visitDate || $visitTime || $sv;
        $lifecycle = app(ContractorAssignmentLifecycleService::class);
        $assignment = $sv ? $lifecycle->present($sv) : [
            'assignment_state' => ContractorAssignmentLifecycleService::OFFERED,
            'assignment_state_label' => $lifecycle->label(ContractorAssignmentLifecycleService::OFFERED),
            'is_confirmed' => false,
        ];
        // Display status for lists: assignment state label — never imply confirmed when only offered
        $displayStatus = $assignment['assignment_state_label'] ?? ($hasVisit ? 'Offered' : ($lead->status ?: 'assigned'));

        return [
            'type' => 'site_visit',
            'id' => $sv?->id ?? ('lead-'.$lead->id),
            'lead_id' => $lead->id,
            'site_visit_id' => $sv?->id,
            'job_title' => ($hasVisit ? 'Site Visit — ' : 'Lead — ').($lead->contact_name ?? 'Customer'),
            'address' => $lead->address,
            'service_category' => $lead->service_category,
            'status' => $displayStatus,
            'lifecycle_status' => $lead->status,
            'assignment_state' => $assignment['assignment_state'] ?? null,
            'assignment_state_label' => $assignment['assignment_state_label'] ?? null,
            'is_confirmed' => (bool) ($assignment['is_confirmed'] ?? false),
            'respond_by' => $assignment['respond_by'] ?? null,
            'contractor_price_status' => $lead->contractor_price ? 'submitted' : 'pending',
            'contractor_submitted_price' => $lead->contractor_price,
            'visit_date' => $visitDate,
            'visit_time' => is_string($visitTime) ? substr($visitTime, 0, 5) : $visitTime,
            'scheduled_start_date' => null,
            'customer' => [
                'id' => $lead->customer_id,
                'name' => $lead->contact_name,
            ],
            'pm' => $lead->assignedPm?->only(['id', 'name', 'email', 'phone']),
            'contact_name' => $lead->contact_name,
            'phone' => $lead->phone,
            'email' => $lead->email,
            'project_description' => $lead->project_description ?? $lead->notes,
            'site_visit_date' => $visitDate,
            'site_visit_time' => is_string($visitTime) ? substr((string) $visitTime, 0, 5) : $visitTime,
            'contractor_price' => $lead->contractor_price,
            'contractor_price_submitted_at' => $lead->contractor_price_submitted_at,
            'contractor_price_notes' => $lead->contractor_price_notes,
            'assigned_pm' => $lead->assignedPm?->only(['id', 'name', 'email', 'phone']),
            'assignment_type' => $assignmentType,
            'url' => '/leads/'.$lead->id,
            'messages_path' => '/leads/'.$lead->id.'/messages',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeJobAssignment(Job $job): array
    {
        return [
            'type' => 'job',
            'id' => $job->id,
            'lead_id' => $job->lead_id,
            'job_title' => $job->job_title,
            'address' => $job->address,
            'service_category' => $job->service_category,
            'status' => $job->status,
            'contractor_price_status' => $job->contractor_price_status,
            'contractor_submitted_price' => $job->contractor_submitted_price,
            'scheduled_start_date' => $job->scheduled_start_date,
            'visit_date' => null,
            'visit_time' => null,
            'customer' => $job->customer?->only(['id', 'name']),
            'pm' => $job->pm?->only(['id', 'name', 'email', 'phone']),
            'url' => '/jobs/'.$job->id,
            'messages_path' => '/jobs/'.$job->id.'/messages',
        ];
    }

    public function contractorOwnsLead(User $contractor, Lead $lead): bool
    {
        if ($contractor->role !== 'contractor') {
            return false;
        }

        return (int) $lead->assigned_contractor_id === (int) $contractor->id
            || (int) $lead->site_visit_contractor_id === (int) $contractor->id
            || SiteVisit::query()
                ->where('lead_id', $lead->id)
                ->where('contractor_id', $contractor->id)
                ->exists();
    }

    public function assertContractorLeadAccess(User $user, Lead $lead): void
    {
        if ($user->role === 'owner') {
            return;
        }
        if ($user->role === 'pm' && (int) $lead->assigned_pm_id === (int) $user->id) {
            return;
        }
        if ($user->role === 'contractor' && $this->contractorOwnsLead($user, $lead)) {
            return;
        }

        throw new HttpException(403, 'Unauthorized.');
    }

    /**
     * Schedule month feed for a contractor — same lead deep-links as Jobs.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function scheduleSiteVisitsForMonth(User $contractor, int $month, int $year): Collection
    {
        $lifecycle = app(ContractorAssignmentLifecycleService::class);

        return SiteVisit::with([
            'lead:id,contact_name,phone,address,service_category,status,assigned_pm_id',
            'lead.assignedPm:id,name',
            'pm:id,name',
            'contractor:id,name',
        ])
            ->where('contractor_id', $contractor->id)
            ->whereMonth('visit_date', $month)
            ->whereYear('visit_date', $year)
            ->where('status', '!=', 'cancelled')
            ->get()
            ->map(function (SiteVisit $sv) use ($lifecycle) {
                $assignment = $lifecycle->present($sv);
                $address = $sv->lead->address ?? '';

                return [
                    'type' => 'site_visit',
                    'id' => $sv->id,
                    'lead_id' => $sv->lead_id,
                    'title' => 'Site Visit — '.($sv->lead->contact_name ?? 'Customer'),
                    'date' => $sv->visit_date?->format('Y-m-d'),
                    'time' => is_string($sv->visit_time) ? substr($sv->visit_time, 0, 5) : $sv->visit_time,
                    'status' => $sv->status,
                    'assignment_state' => $assignment['assignment_state'],
                    'assignment_state_label' => $assignment['assignment_state_label'],
                    'is_confirmed' => $assignment['is_confirmed'],
                    'event_type_label' => 'Site visit',
                    'address' => $address,
                    'customer_name' => $sv->lead->contact_name ?? '',
                    'customer_phone' => $sv->lead->phone ?? null,
                    'pm_name' => $sv->pm?->name ?? $sv->lead?->assignedPm?->name ?? '',
                    'contractor_name' => $sv->contractor?->name ?? '',
                    'url' => $sv->lead_id ? '/leads/'.$sv->lead_id : '/site-visits/'.$sv->id,
                    'directions_url' => $address !== ''
                        ? 'https://www.google.com/maps/dir/?api=1&destination='.rawurlencode($address)
                        : null,
                    'next_action' => $assignment['is_confirmed']
                        ? 'Open visit details'
                        : ('Respond — currently '.$assignment['assignment_state_label']),
                    'color' => $assignment['is_confirmed'] ? 'indigo' : 'orange',
                ];
            });
    }
}
