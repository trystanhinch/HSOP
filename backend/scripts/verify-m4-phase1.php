<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\AiActionLog;
use App\Models\AiActionType;
use App\Models\CompanySource;
use App\Models\Lead;
use App\Models\NextAction;
use App\Models\Setting;
use App\Models\User;
use App\Services\AiActionAuthorizer;
use App\Services\ActivityTimelineService;

$auth = app(AiActionAuthorizer::class);
$ai = User::aiSuperAdmin();
$owner = User::where('role', 'owner')->first();

echo "=== Milestone 4 Phase 1 Verification ===\n\n";

echo "1. AI Super Admin user: ".($ai ? "OK (id={$ai->id}, role={$ai->role})" : "MISSING")."\n";

echo "2. Owner-only blocks AI: ";
echo ($auth->canPerform($ai, 'change_ai_kill_switch') ? 'FAIL' : 'OK')."\n";

echo "3. Owner can change kill switch: ";
echo ($auth->canPerform($owner, 'change_ai_kill_switch') ? 'OK' : 'FAIL')."\n";

echo "4. AI can create_lead (bounded): ";
echo ($auth->canPerform($ai, 'create_lead') ? 'OK' : 'FAIL')."\n";

echo "5. AI cannot hard_delete: ";
echo ($auth->canPerform($ai, 'hard_delete_record') ? 'FAIL' : 'OK')."\n";

echo "6. AiActionType registry count: ".AiActionType::count()."\n";

echo "7. Kill switch default: ".(Setting::getBool('ai_kill_switch') ? 'ON' : 'OFF (expected)')."\n";

echo "8. Module mode lead_intake: ".Setting::get('ai_mode_lead_intake', 'missing')."\n";

$log = AiActionLog::create([
    'trigger_event' => 'cli_verification',
    'actor_id' => $ai->id,
    'decision' => 'CLI test entry',
    'action_taken' => 'test_action',
    'required_human_approval' => true,
]);
echo "9. AiActionLog insert: OK (id={$log->id})\n";

$source = CompanySource::create([
    'company_name' => 'Test Source Co',
    'domain' => 'test.example.com',
    'status' => 'testing',
]);
echo "10. CompanySource CRUD: OK (id={$source->id})\n";
$source->delete();

$lead = Lead::first();
if ($lead) {
    $na = $lead->nextActions()->create([
        'action_description' => 'Follow up with customer',
        'responsible_role' => 'pm',
        'status' => 'pending',
    ]);
    echo "11. NextAction on Lead: OK (id={$na->id})\n";

    $entry = app(ActivityTimelineService::class)->record($lead, 'lead_created', 'Test timeline entry', $owner);
    echo "12. ActivityTimeline on Lead: OK (id={$entry->id})\n";
} else {
    echo "11-12. Skipped (no leads in DB)\n";
}

echo "\nAll checks complete.\n";
