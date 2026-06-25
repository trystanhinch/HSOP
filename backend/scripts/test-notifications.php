<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "=== SMS Test ===\n";
\App\Models\Setting::updateOrCreate(['key' => 'sms_globally_enabled'], ['value' => 'true']);
$result = app(\App\Services\SmsService::class)->send(
    '+16045551234',
    'Test message from HSOP Job Command',
    'manual_test',
    null,
    null
);
echo json_encode($result, JSON_PRETTY_PRINT)."\n";

echo "\n=== Email Test ===\n";
\App\Models\Setting::updateOrCreate(['key' => 'email_globally_enabled'], ['value' => 'true']);
try {
    \Illuminate\Support\Facades\Mail::raw('Test email from HSOP Job Command', function ($msg) {
        $msg->to('test@example.com')->subject('HSOP Test');
    });
    echo "Email: sent (or logged to mail driver)\n";
    echo "MAIL_MAILER=".config('mail.default')."\n";
} catch (Throwable $e) {
    echo "Email FAIL: {$e->getMessage()}\n";
}
