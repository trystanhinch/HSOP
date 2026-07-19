<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Phase 3 status model (canonical) + legacy aliases still in use
    |--------------------------------------------------------------------------
    | We EXTEND rather than wipe existing MySQL ENUMs. Live data keeps working;
    | new code prefers canonical values. See WorkflowStatusMapper.
    */
    'lead' => [
        'canonical' => [
            'new', 'duplicate_review', 'pm_assigned', 'customer_contacted',
            'call_scheduled', 'site_visit_scheduled', 'converted', 'disqualified',
        ],
        // Still accepted for writes / existing rows
        'legacy' => ['contacted', 'quote_needed', 'lost'],
        'aliases' => [
            'contacted' => 'customer_contacted',
            'lost' => 'disqualified',
        ],
    ],

    'quote' => [
        'canonical' => [
            'pricing_requested', 'pricing_received', 'draft', 'sent', 'viewed',
            'follow_up', 'approved', 'declined', 'expired',
        ],
        'legacy' => ['rejected', 'revised'],
        'aliases' => [
            'rejected' => 'declined',
        ],
    ],

    'job' => [
        'canonical' => [
            'created', 'waiting_to_schedule', 'scheduled', 'in_progress',
            'update_posted', 'completion_requested', 'revision_requested',
            'revision_in_progress', 'completion_accepted', 'closed',
        ],
        // Full Milestone 3 set remains valid on the jobs table
        'legacy' => [
            'new_job', 'contractor_assigned', 'site_visit_scheduled', 'site_visit_completed',
            'contractor_pricing_pending', 'quote_sent', 'estimate_sent', 'quote_approved',
            'estimate_accepted', 'start_date_scheduled', 'progress_updated', 'waiting_on_customer',
            'ready_for_review', 'pending_customer_approval', 'corrections_required',
            'completed_by_contractor', 'final_review', 'completed', 'payment_pending',
            'etransfer_pending_confirmation', 'paid_completed', 'invoiced', 'paid', 'cancelled',
        ],
        'aliases' => [
            'new_job' => 'created',
            'quote_approved' => 'waiting_to_schedule',
            'estimate_accepted' => 'waiting_to_schedule',
            'progress_updated' => 'update_posted',
            'pending_customer_approval' => 'completion_requested',
            'corrections_required' => 'revision_in_progress',
            'payment_pending' => 'completion_accepted',
            'paid_completed' => 'closed',
            'completed' => 'closed',
            'paid' => 'closed',
            'cancelled' => 'closed',
        ],
        'customer_labels' => [
            'created' => 'Project created',
            'waiting_to_schedule' => 'Waiting to schedule',
            'scheduled' => 'Scheduled',
            'in_progress' => 'Your project is underway',
            'update_posted' => 'Your project is underway',
            'completion_requested' => 'Work complete — please review',
            'revision_requested' => 'Revision requested',
            'revision_in_progress' => 'Revision in progress',
            'completion_accepted' => 'Completion accepted',
            'closed' => 'Project closed',
            // legacy passthroughs
            'quote_approved' => 'Waiting to schedule',
            'progress_updated' => 'Your project is underway',
            'pending_customer_approval' => 'Work complete — please review',
            'payment_pending' => 'Awaiting payment',
            'paid_completed' => 'Completed',
            'completed' => 'Completed',
        ],
    ],

    'payment' => [
        'canonical' => [
            'invoice_draft', 'invoice_sent', 'payment_pending', 'payment_failed',
            'paid', 'refunded', 'disputed',
        ],
    ],

    'payout' => [
        'canonical' => [
            'not_eligible', 'waiting_for_completion_acceptance', 'waiting_for_payment',
            'waiting_for_revision_closure', 'eligible', 'scheduled', 'pending',
            'in_transit', 'paid', 'failed', 'on_hold',
        ],
        'legacy' => ['not_ready', 'ready_for_payout', 'approved', 'hold_issue'],
        'aliases' => [
            'not_ready' => 'not_eligible',
            'ready_for_payout' => 'eligible',
            'approved' => 'scheduled',
            'hold_issue' => 'on_hold',
        ],
    ],

    'review' => [
        'canonical' => [
            'pending', 'internal_rating_received', 'google_link_shown',
            'internal_issue_opened', 'follow_up_required', 'resolved',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Configurable automation thresholds (Owner-editable via settings table)
    |--------------------------------------------------------------------------
    */
    'thresholds' => [
        'pm_contact_lead_hours' => 4,
        'pm_contact_escalation_hours' => 4,
        'contractor_pricing_deadline_hours' => 24,
        'quote_follow_up_hours' => 48,
        'job_missing_update_days' => 7,
    ],

    'threshold_keys' => [
        'pm_contact_lead_hours' => 'workflow_pm_contact_lead_hours',
        'pm_contact_escalation_hours' => 'workflow_pm_contact_escalation_hours',
        'contractor_pricing_deadline_hours' => 'workflow_contractor_pricing_deadline_hours',
        'quote_follow_up_hours' => 'workflow_quote_follow_up_hours',
        'job_missing_update_days' => 'workflow_job_missing_update_days',
    ],
];
