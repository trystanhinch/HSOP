<?php

return [
    /*
    |--------------------------------------------------------------------------
    | AI Operating Modules
    |--------------------------------------------------------------------------
    */
    'modules' => [
        'lead_intake',
        'customer_messaging',
        'payouts',
        'reviews',
    ],

    'default_mode' => 'suggestion',

    'modes' => ['suggestion', 'assisted', 'autopilot'],

    /*
    |--------------------------------------------------------------------------
    | Allowed AI Action Types (registry)
    |--------------------------------------------------------------------------
    | permission_level: owner | ai_super_admin
    | modes_available: which operating modes allow this action
    */
    'actions' => [
        'create_lead' => [
            'label' => 'Create Lead',
            'permission_level' => 'ai_super_admin',
            'requires_human_approval' => true,
            'modes_available' => ['assisted', 'autopilot'],
            'description' => 'Create a new lead from parsed intake data.',
        ],
        'send_customer_message' => [
            'label' => 'Send Customer Message',
            'permission_level' => 'ai_super_admin',
            'requires_human_approval' => true,
            'modes_available' => ['suggestion', 'assisted', 'autopilot'],
            'description' => 'Send an approved outbound message to a customer.',
        ],
        'create_next_action' => [
            'label' => 'Create Next Action',
            'permission_level' => 'ai_super_admin',
            'requires_human_approval' => false,
            'modes_available' => ['suggestion', 'assisted', 'autopilot'],
            'description' => 'Set or update the next action on a lead or job.',
        ],
        'escalate_to_pm' => [
            'label' => 'Escalate to PM',
            'permission_level' => 'ai_super_admin',
            'requires_human_approval' => false,
            'modes_available' => ['assisted', 'autopilot'],
            'description' => 'Escalate an item to the assigned PM.',
        ],
        'create_quote_draft' => [
            'label' => 'Create Quote Draft',
            'permission_level' => 'ai_super_admin',
            'requires_human_approval' => true,
            'modes_available' => ['suggestion', 'assisted'],
            'description' => 'Draft a quote for PM review before sending.',
        ],
        'update_lead_status' => [
            'label' => 'Update Lead Status',
            'permission_level' => 'ai_super_admin',
            'requires_human_approval' => false,
            'modes_available' => ['assisted', 'autopilot'],
            'description' => 'Update lead workflow status.',
        ],
        'update_job_status' => [
            'label' => 'Update Job Status',
            'permission_level' => 'ai_super_admin',
            'requires_human_approval' => false,
            'modes_available' => ['assisted', 'autopilot'],
            'description' => 'Update job workflow status.',
        ],
        'archive_record' => [
            'label' => 'Archive Record',
            'permission_level' => 'ai_super_admin',
            'requires_human_approval' => true,
            'modes_available' => ['assisted'],
            'description' => 'Archive or flag a record (no hard delete).',
        ],
        'create_internal_note' => [
            'label' => 'Create Internal Note',
            'permission_level' => 'ai_super_admin',
            'requires_human_approval' => false,
            'modes_available' => ['suggestion', 'assisted', 'autopilot'],
            'description' => 'Add an internal note to a lead or job.',
        ],
    ],
];
