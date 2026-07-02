<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$testPhone = $argv[1] ?? getenv('TEST_SMS_PHONE') ?: null;
$testEmail = $argv[2] ?? getenv('TEST_EMAIL') ?: null;

echo "=== STEP 2: Settings ===\n";
$beforeSms = \App\Models\Setting::where('key', 'sms_globally_enabled')->value('value');
echo "sms_globally_enabled BEFORE: ".var_export($beforeSms, true)."\n";
if ($beforeSms !== 'true') {
    \App\Models\Setting::updateOrCreate(['key' => 'sms_globally_enabled'], ['value' => 'true']);
}
$afterSms = \App\Models\Setting::where('key', 'sms_globally_enabled')->value('value');
echo "sms_globally_enabled AFTER: {$afterSms}\n";

$beforeEmail = \App\Models\Setting::where('key', 'email_globally_enabled')->value('value');
echo "email_globally_enabled BEFORE: ".var_export($beforeEmail, true)."\n";

echo "\n=== ENV CHECK ===\n";
echo "TWILIO_SID: ".(str_starts_with(env('TWILIO_SID', ''), 'AC') ? 'valid format (AC...)' : 'INVALID')."\n";
echo "TWILIO_FROM: ".env('TWILIO_FROM_NUMBER')."\n";
echo "SMS_ENABLED: ".(env('SMS_ENABLED') ? 'true' : 'false')."\n";
echo "MAIL_MAILER: ".env('MAIL_MAILER')."\n";
echo "MAIL_HOST: ".env('MAIL_HOST')."\n";
echo "Twilio SDK installed: ".(class_exists(\Twilio\Rest\Client::class) ? 'yes' : 'NO')."\n";

echo "\n=== STEP 3: SMS Test ===\n";
if (! $testPhone) {
    echo "SKIP: No test phone provided. Usage: php scripts/verify-sms-email.php +16045551234 you@email.com\n";
} else {
    $result = app(\App\Services\SmsService::class)->send(
        $testPhone,
        'Test message from ServiceOP. If you received this, Twilio is working correctly.',
        'manual_test',
        null,
        null
    );
    echo "Send result: ".json_encode($result)."\n";
    $log = \App\Models\SmsLog::latest()->first();
    if ($log) {
        echo "SMS Log: to={$log->to_phone} status={$log->status} sid={$log->provider_message_id} error={$log->error_message}\n";
    }
}

echo "\n=== STEP 4: Email Test ===\n";
if (! $testEmail) {
    echo "SKIP: No test email provided.\n";
} elseif (env('MAIL_MAILER') === 'log') {
    echo "FAIL: MAIL_MAILER is 'log' — emails are written to log only, not sent via SMTP.\n";
} else {
    try {
        \Illuminate\Support\Facades\Mail::raw(
            'Test email from ServiceOP. If you received this, your SMTP is configured correctly.',
            function ($message) use ($testEmail) {
                $message->to($testEmail)->subject('ServiceOP SMTP Test');
            }
        );
        echo "Send attempted without exception.\n";
    } catch (\Throwable $e) {
        echo "FAIL: ".$e->getMessage()."\n";
    }
    $elog = \App\Models\EmailLog::latest()->first();
    if ($elog) {
        echo "Email Log: to={$elog->to_email} status={$elog->status} error={$elog->error_message}\n";
    }
}

echo "\n=== STEP 9: Formula ===\n";
$quote = \App\Models\Quote::where('contractor_base_price', '>', 0)->latest()->first();
if ($quote) {
    $ratio = round($quote->customer_price_before_gst / $quote->contractor_base_price, 2);
    echo "Quote {$quote->quote_number}: ratio={$ratio} pm={$quote->pm_amount} company={$quote->company_amount}\n";
} else {
    echo "No quote with contractor price found.\n";
}
