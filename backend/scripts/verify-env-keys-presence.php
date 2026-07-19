<?php

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$out = [
    'AI_PROVIDER' => config('ai.provider'),
    'OPENAI_KEY_SET' => (bool) config('ai.openai.api_key'),
    'OPENAI_KEY_LEN' => strlen((string) config('ai.openai.api_key')),
    'OPENAI_MODEL' => config('ai.openai.model'),
    'GOOGLE_ID_SET' => (bool) config('gmail.client_id'),
    'GOOGLE_ID_LEN' => strlen((string) config('gmail.client_id')),
    'GOOGLE_SECRET_SET' => (bool) config('gmail.client_secret'),
    'GOOGLE_SECRET_LEN' => strlen((string) config('gmail.client_secret')),
    'REDIRECT' => config('gmail.redirect_uri'),
    'MAILBOX' => config('gmail.mailbox'),
    'GMAIL_CONFIGURED' => app(\App\Services\Gmail\GmailOAuthService::class)->isConfigured(),
];

echo json_encode($out, JSON_PRETTY_PRINT).PHP_EOL;
