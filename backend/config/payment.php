<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Payment Provider
    |--------------------------------------------------------------------------
    | mock — local lifecycle without Stripe (default for automated tests)
    | stripe — real Stripe (test or live keys via env)
    */
    'provider' => env('PAYMENT_PROVIDER', 'mock'),

    'providers' => [
        'mock' => \App\Services\Payments\MockPaymentProvider::class,
        'stripe' => \App\Services\Payments\StripePaymentProvider::class,
    ],

    'stripe' => [
        // Prefer STRIPE_SECRET_KEY; STRIPE_SECRET kept for backwards compatibility
        'secret' => env('STRIPE_SECRET_KEY', env('STRIPE_SECRET')),
        'publishable' => env('STRIPE_PUBLISHABLE_KEY'),
        // Platform Event Destination (Your account)
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        // Connected accounts Event Destination (same URL, different signing secret)
        'connect_webhook_secret' => env('STRIPE_CONNECT_WEBHOOK_SECRET'),
        'currency' => env('STRIPE_CURRENCY', 'cad'),
    ],

    'invoice' => [
        'number_format' => env('INVOICE_NUMBER_FORMAT', 'INV-{XXXX}'),
        'number_pad' => (int) env('INVOICE_NUMBER_PAD', 4),
    ],

    'payout' => [
        'schedule_business_days' => (int) env('PAYOUT_SCHEDULE_BUSINESS_DAYS', 2),
    ],
];
