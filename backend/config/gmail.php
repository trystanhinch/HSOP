<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Gmail inbox for lead intake (leads@serviceop.ca)
    |--------------------------------------------------------------------------
    | Option A — OAuth 2.0 + Gmail API (readonly). Refresh token is stored
    | encrypted in gmail_oauth_tokens (never plain in .env).
    */
    // Prefer GOOGLE_OAUTH_* (Trystan's naming); fall back to GOOGLE_CLIENT_*.
    'client_id' => env('GOOGLE_OAUTH_CLIENT_ID', env('GOOGLE_CLIENT_ID')),
    'client_secret' => env('GOOGLE_OAUTH_CLIENT_SECRET', env('GOOGLE_CLIENT_SECRET')),
    'redirect_uri' => env('GOOGLE_REDIRECT_URI', env('APP_URL').'/oauth/gmail/callback'),
    'mailbox' => env('GMAIL_MAILBOX', 'leads@serviceop.ca'),

    // Readonly is enough — we track processed IDs in our DB for idempotency
    // (no gmail.modify / mark-as-read unless we need it later).
    'scopes' => [
        'https://www.googleapis.com/auth/gmail.readonly',
    ],

    'poll_query' => env('GMAIL_POLL_QUERY', 'in:inbox newer_than:14d'),
    'max_results' => (int) env('GMAIL_MAX_RESULTS', 25),
    'enabled' => (bool) env('GMAIL_FETCH_ENABLED', true),
];
