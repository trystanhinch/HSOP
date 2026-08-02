<?php

/**
 * Milestone 6B Phase 1 — Learning AI gateway identity & abilities.
 *
 * ASSUMPTION (pending Owner confirmation from 6B Phase 0 audit):
 * Dedicated `learning_ai` role — parallel to `external_review_ai`, never
 * shared with ai_super_admin or External Review AI.
 */
return [
    'actor_role' => 'learning_ai',
    'actor_email' => env('LEARNING_AI_ACTOR_EMAIL', 'learning-ai@serviceop.system'),

    'kill_switch_setting_key' => 'learning_gateway_kill_switch',

    'abilities' => [
        'learning:read',
        'learning:eligibility-write',
        'learning:evidence-write',
    ],

    /** Default ability for read routes (ping / future corpus tools). */
    'required_ability' => 'learning:read',

    'token_default_ttl_days' => (int) env('LEARNING_AI_TOKEN_TTL_DAYS', 90),
    'token_expiry_warning_days' => (int) env('LEARNING_AI_TOKEN_EXPIRY_WARNING_DAYS', 14),

    /*
    | Eligibility statuses (human-driven transitions only in Phase 1).
    */
    'eligibility_statuses' => [
        'pending_review',
        'provisional',
        'verified',
        'excluded',
    ],

    'eligibility_default' => 'pending_review',
];
