<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$raw = <<<'EMAIL'
Name: Test No PM
Phone: 604-555-9999
Email: nopm.fallback@example.com
Service: Drywall repair
Message: Test message for PM fallback.
Submitted: today
EMAIL;

$pipeline = app(\App\Services\LeadIntake\LeadIntakePipeline::class);
$result = $pipeline->process($raw, sendNotifications: false);

$lead = $result->lead;
$na = \App\Models\NextAction::where('subject_id', $lead->id)
    ->where('subject_type', $lead->getMorphClass())
    ->latest()
    ->first();

echo "Lead #{$lead->id}\n";
echo "assigned_pm_id: ".($lead->assigned_pm_id ?? 'null')."\n";
echo "NextAction role: {$na->responsible_role}\n";
echo "NextAction desc: {$na->action_description}\n";
