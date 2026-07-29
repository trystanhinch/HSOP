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
        'escalations',
        'command_center',
        'public_intake',
    ],

    'default_mode' => 'suggestion',

    // Escalations start draft-only until Owner promotes to assisted/autopilot.
    'module_defaults' => [
        'escalations' => 'suggestion',
    ],

    /*
    | Plain-language modes (A-17). Backend enforces these per action via AiActionGate.
    | UI label "Auto" maps to registry key "autopilot".
    */
    'modes' => ['suggestion', 'assisted', 'autopilot'],

    'mode_definitions' => [
        'suggestion' => [
            'label' => 'Suggestion',
            'summary' => 'AI drafts recommendations only. Nothing is sent or changed until a human acts.',
        ],
        'assisted' => [
            'label' => 'Assisted',
            'summary' => 'AI may prepare low-risk changes and stage drafts. High-risk work still needs explicit approval.',
        ],
        'autopilot' => [
            'label' => 'Auto',
            'summary' => 'AI may execute low/medium-risk actions automatically. Money, customer messages, deletions, status changes, and payouts still require approval (hard floor).',
        ],
    ],

    /*
    | Daily / cost / retry ceilings (A-17). Overridable via Settings keys of the same name.
    */
    'limits' => [
        'daily_action_limit' => 200,
        'daily_cost_usd_limit' => 25.0,
        'max_retries_per_action' => 2,
    ],

    'prompt_version' => 'cc-ops-v1',

    /*
    |--------------------------------------------------------------------------
    | Allowed AI Action Types (registry)
    |--------------------------------------------------------------------------
    | risk_level: low | medium | high | critical
    | hard_approval_floor: true → approval required even in autopilot (money, messages, delete, status, payouts)
    | module: which ai_mode_* setting gates this action
    */
    'actions' => [
        'create_lead' => [
            'label' => 'Create Lead',
            'permission_level' => 'ai_super_admin',
            'requires_human_approval' => true,
            'hard_approval_floor' => true,
            'risk_level' => 'high',
            'module' => 'lead_intake',
            'modes_available' => ['assisted', 'autopilot'],
            'description' => 'Create a new lead from parsed intake data.',
        ],
        'send_customer_message' => [
            'label' => 'Send Customer Message',
            'permission_level' => 'ai_super_admin',
            'requires_human_approval' => true,
            'hard_approval_floor' => true,
            'risk_level' => 'critical',
            'module' => 'customer_messaging',
            'modes_available' => ['suggestion', 'assisted', 'autopilot'],
            'description' => 'Send an approved outbound message to a customer.',
        ],
        'create_next_action' => [
            'label' => 'Create Next Action',
            'permission_level' => 'ai_super_admin',
            'requires_human_approval' => false,
            'hard_approval_floor' => false,
            'risk_level' => 'low',
            'module' => 'escalations',
            'modes_available' => ['suggestion', 'assisted', 'autopilot'],
            'description' => 'Set or update the next action on a lead or job.',
        ],
        'escalate_to_pm' => [
            'label' => 'Escalate to PM',
            'permission_level' => 'ai_super_admin',
            'requires_human_approval' => false,
            'hard_approval_floor' => false,
            'risk_level' => 'medium',
            'module' => 'escalations',
            'modes_available' => ['assisted', 'autopilot'],
            'description' => 'Escalate an item to the assigned PM.',
        ],
        'create_quote_draft' => [
            'label' => 'Create Quote Draft',
            'permission_level' => 'ai_super_admin',
            'requires_human_approval' => true,
            'hard_approval_floor' => true,
            'risk_level' => 'high',
            'module' => 'lead_intake',
            'modes_available' => ['suggestion', 'assisted'],
            'description' => 'Draft a quote for PM review before sending.',
        ],
        'update_lead_status' => [
            'label' => 'Update Lead Status',
            'permission_level' => 'ai_super_admin',
            'requires_human_approval' => true,
            'hard_approval_floor' => true,
            'risk_level' => 'high',
            'module' => 'lead_intake',
            'modes_available' => ['assisted', 'autopilot'],
            'description' => 'Update lead workflow status.',
        ],
        'update_job_status' => [
            'label' => 'Update Job Status',
            'permission_level' => 'ai_super_admin',
            'requires_human_approval' => true,
            'hard_approval_floor' => true,
            'risk_level' => 'high',
            'module' => 'escalations',
            'modes_available' => ['assisted', 'autopilot'],
            'description' => 'Update job workflow status.',
        ],
        'archive_record' => [
            'label' => 'Archive Record',
            'permission_level' => 'ai_super_admin',
            'requires_human_approval' => true,
            'hard_approval_floor' => true,
            'risk_level' => 'critical',
            'module' => 'lead_intake',
            'modes_available' => ['assisted'],
            'description' => 'Archive or flag a record (no hard delete).',
        ],
        'create_internal_note' => [
            'label' => 'Create Internal Note',
            'permission_level' => 'ai_super_admin',
            'requires_human_approval' => false,
            'hard_approval_floor' => false,
            'risk_level' => 'low',
            'module' => 'lead_intake',
            'modes_available' => ['suggestion', 'assisted', 'autopilot'],
            'description' => 'Add an internal note to a lead or job.',
        ],
        'command_center_query' => [
            'label' => 'Command Center Query',
            'permission_level' => 'owner',
            'requires_human_approval' => false,
            'hard_approval_floor' => false,
            'risk_level' => 'low',
            'module' => 'command_center',
            'modes_available' => ['suggestion', 'assisted', 'autopilot'],
            'description' => 'Owner AI Command Center read-only data queries.',
        ],
        'command_center_draft_pm_message' => [
            'label' => 'Command Center Draft PM Message',
            'permission_level' => 'owner',
            'requires_human_approval' => true,
            'hard_approval_floor' => true,
            'risk_level' => 'critical',
            'module' => 'command_center',
            'modes_available' => ['suggestion', 'assisted', 'autopilot'],
            'description' => 'Draft a PM message from Command Center; requires owner confirm to send.',
        ],
        'command_center_create_next_action' => [
            'label' => 'Command Center Create Next Action',
            'permission_level' => 'owner',
            'requires_human_approval' => false,
            'hard_approval_floor' => false,
            'risk_level' => 'low',
            'module' => 'command_center',
            'modes_available' => ['suggestion', 'assisted', 'autopilot'],
            'description' => 'Create a NextAction from Command Center (low risk).',
        ],
        'initiate_payout' => [
            'label' => 'Initiate Payout',
            'permission_level' => 'owner',
            'requires_human_approval' => true,
            'hard_approval_floor' => true,
            'risk_level' => 'critical',
            'module' => 'payouts',
            'modes_available' => ['assisted', 'autopilot'],
            'description' => 'Queue or release a payout — always requires human approval.',
        ],
        'public_intake_chat' => [
            'label' => 'Public Intake Chat',
            'permission_level' => 'ai_super_admin',
            'requires_human_approval' => false,
            'hard_approval_floor' => false,
            'risk_level' => 'low',
            'module' => 'public_intake',
            'modes_available' => ['suggestion', 'assisted', 'autopilot'],
            'description' => 'Conversational AI for the public multi-tenant website intake chat.',
        ],
    ],
];
