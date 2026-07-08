<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Owner-Only Actions (irreversible / highest-risk)
    |--------------------------------------------------------------------------
    | These actions can ONLY be performed by owner/admin role.
    | AI Super Admin and other roles are always denied.
    */
    'owner_only' => [
        'change_ai_permission_level',
        'change_ai_kill_switch',
        'change_ai_operating_mode',
        'change_stripe_config',
        'change_payment_config',
        'change_payout_split_rules',
        'change_tax_legal_settings',
        'grant_admin_access',
        'disable_audit_logs',
        'alter_audit_logs',
        'hard_delete_record',
        'approve_large_refund',
        'approve_large_payout',
        'modify_deployment',
        'modify_infrastructure',
    ],

    /*
    |--------------------------------------------------------------------------
    | AI Super Admin Bounded Actions
    |--------------------------------------------------------------------------
    | Broad operational actions AI may perform (when kill switch off and mode allows).
    | Hard deletes are explicitly excluded — use archive_record instead.
    */
    'ai_allowed' => [
        'read_all_modules',
        'create_lead',
        'update_lead',
        'create_customer',
        'update_customer',
        'create_task',
        'create_note',
        'create_next_action',
        'update_next_action',
        'assign_pm',
        'assign_contractor',
        'update_lead_status',
        'update_job_status',
        'create_quote_draft',
        'update_quote',
        'create_invoice_draft',
        'update_job_record',
        'create_payout_record',
        'create_reminder',
        'create_review_item',
        'send_approved_message',
        'escalate_to_pm',
        'archive_record',
        'flag_record',
        'create_internal_note',
    ],

    /*
    |--------------------------------------------------------------------------
    | Actions explicitly forbidden for AI Super Admin
    |--------------------------------------------------------------------------
    */
    'ai_forbidden' => [
        'hard_delete_record',
        'change_ai_kill_switch',
        'change_ai_operating_mode',
        'change_stripe_config',
        'change_payment_config',
        'change_payout_split_rules',
        'grant_admin_access',
        'disable_audit_logs',
        'alter_audit_logs',
        'modify_deployment',
        'modify_infrastructure',
    ],
];
