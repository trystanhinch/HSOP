<?php

/**
 * Milestone 6A — External Review AI gateway.
 *
 * Phase 4: dedicated `external_review_ai` role (never inherits ai_super_admin).
 * Abilities use Sanctum personal_access_tokens.abilities.
 */
return [
    /*
    |--------------------------------------------------------------------------
    | Service identity (Phase 4)
    |--------------------------------------------------------------------------
    */
    'actor_role' => 'external_review_ai',
    'actor_email' => env('REVIEW_AI_ACTOR_EMAIL', 'external-review-ai@serviceop.system'),

    /*
    |--------------------------------------------------------------------------
    | Kill switch (same Settings pattern as ai_kill_switch)
    |--------------------------------------------------------------------------
    | When true, ALL /api/review-gateway/* requests are rejected regardless of
    | token validity. Default false = gateway available.
    */
    'kill_switch_setting_key' => 'review_gateway_kill_switch',

    /*
    |--------------------------------------------------------------------------
    | Sanctum abilities for External Review AI tokens
    |--------------------------------------------------------------------------
    */
    'abilities' => [
        'review:read',
        'review:code-read',
        'review:evidence-write',
    ],

    /** Ability required for every tool route in this phase (read-only tools). */
    'required_ability' => 'review:read',

    /*
    |--------------------------------------------------------------------------
    | Token expiration
    |--------------------------------------------------------------------------
    */
    'token_default_ttl_days' => (int) env('REVIEW_AI_TOKEN_TTL_DAYS', 90),
    /** Days before expiry when Review Center summary flags a token. */
    'token_expiry_warning_days' => (int) env('REVIEW_AI_TOKEN_EXPIRY_WARNING_DAYS', 14),

    'tool_versions' => [
        'lead_journey' => '1.0.0',
        'search' => '1.0.0',
        'ai_conversation_log' => '1.0.0',
        'source_file' => '1.0.0',
        'source_search' => '1.0.0',
        'evaluation_run' => '1.0.0',
        'evaluation_finding' => '1.0.0',
    ],

    /*
    |--------------------------------------------------------------------------
    | Milestone 6A.3 — Evaluation harness (provider-neutral metadata)
    |--------------------------------------------------------------------------
    | Core Evaluation Dimensions from the 6A package (initial enum set).
    | Smoke scorer uses a subset; full adversarial scoring needs staging.
    */
    'evaluation' => [
        'dimensions' => [
            'scope_completeness',
            'pricing_timing',
            'factual_grounding',
            'tool_correctness',
            'authorization',
            'safety_escalation',
            'privacy_security',
            'consistency',
        ],
        'statement_kinds' => [
            'observed_fact',
            'inference',
            'recommendation',
        ],
        'subject_types' => [
            'ai_conversation_log',
            'ai_action_log',
        ],
        'run_types' => [
            'manual',
            'scheduled',
            'triggered-by-change',
            'smoke',
        ],
        'run_statuses' => [
            'running',
            'completed',
            'failed',
        ],
        'default_provider' => env('REVIEW_EVAL_PROVIDER', 'openai'),
        'default_model' => env('REVIEW_EVAL_MODEL', 'placeholder-scorer'),
        'default_model_version' => env('REVIEW_EVAL_MODEL_VERSION', 'smoke-1'),
        'prompt_version' => env('REVIEW_EVAL_PROMPT_VERSION', 'smoke-placeholder-1.0.0'),
        'evaluation_version' => env('REVIEW_EVAL_VERSION', '1.0.0'),
        'benchmark_set_version' => env('REVIEW_EVAL_BENCHMARK_SET', 'smoke-local-v1'),
    ],

    /*
    | Sensitive field / pattern denylist applied to every gateway JSON payload.
    | Keys matching these (case-insensitive) are stripped; string values matching
    | patterns are redacted. Tests assert none of these survive in responses.
    */
    'sensitive_key_denylist' => [
        'password',
        'password_hash',
        'remember_token',
        'plain_text_token',
        'token',
        'api_token',
        'api_key',
        'secret',
        'client_secret',
        'auth_token',
        'access_token',
        'refresh_token',
        'stripe_secret',
        'stripe_secret_key',
        'card_number',
        'card_cvc',
        'cvc',
        'cvv',
        'pan',
        'customer_portal_token',
        'portal_token',
        'openai_api_key',
        'twilio_auth_token',
        'resend_api_key',
        'deploy_secret',
        'private_key',
    ],

    'sensitive_value_patterns' => [
        '/sk_live_[A-Za-z0-9]+/',
        '/sk_test_[A-Za-z0-9]+/',
        '/sk-proj-[A-Za-z0-9_-]+/',
        '/whsec_[A-Za-z0-9]+/',
        '/rk_live_[A-Za-z0-9]+/',
        '/rk_test_[A-Za-z0-9]+/',
    ],
];
