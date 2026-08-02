<?php

/**
 * Milestone 6A.2 — Staging isolation & safety configuration.
 *
 * STAGING_MODE must be explicitly true on staging apps. Do not rely on APP_ENV alone.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Expected production identifiers (for staging:verify-isolation)
    |--------------------------------------------------------------------------
    | Staging must NOT use these DB host/database names. Set to your real
    | production values so verify-isolation can detect accidental prod pointing.
    | Leave blank to skip that particular comparison.
    */
    'forbidden_production_db_hosts' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('STAGING_FORBIDDEN_PROD_DB_HOSTS', ''))
    ))),

    'forbidden_production_db_names' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('STAGING_FORBIDDEN_PROD_DB_NAMES', 'hsop_job_command,serviceop_production,serviceop_prod'))
    ))),

    /*
    |--------------------------------------------------------------------------
    | HTTP Basic Auth (staging gate)
    |--------------------------------------------------------------------------
    */
    'basic_auth_user' => env('STAGING_BASIC_AUTH_USER'),
    'basic_auth_password' => env('STAGING_BASIC_AUTH_PASSWORD'),

    /*
    | Paths exempt from Basic Auth when staging_mode is true.
    | /up — DigitalOcean / load-balancer health probes.
    | api/stripe/webhook — Stripe can send Basic Auth if configured; default
    | exempt so test-mode webhooks remain receivable; tighten if desired.
    */
    'basic_auth_except' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env(
            'STAGING_BASIC_AUTH_EXCEPT',
            'up,api/stripe/webhook'
        ))
    ))),

    /*
    |--------------------------------------------------------------------------
    | Mailers allowed under staging_mode
    |--------------------------------------------------------------------------
    */
    'allowed_mail_mailers' => ['log', 'array', 'failover'],

    /*
    | Optional Twilio Account SID allowlist when SMS_ENABLED=true on staging.
    | Twilio SIDs do not encode live vs test (both look like AC…); prefer SMS_ENABLED=false.
    */
    'allowed_twilio_sids' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('STAGING_ALLOWED_TWILIO_SIDS', ''))
    ))),
];
