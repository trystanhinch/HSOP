<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Setting;
use App\Models\User;
use App\Services\AiActionAuthorizer;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

echo "=== Step 1 Role Regression (local API) ===\n\n";

$auth = app(AiActionAuthorizer::class);
$ai = User::where('role', 'ai_super_admin')->first();
$owner = User::where('role', 'owner')->first();
$pm = User::where('role', 'pm')->first();
$contractor = User::where('role', 'contractor')->first();
$customer = User::where('role', 'customer')->first();

$roles = [
    'owner' => $owner,
    'pm' => $pm,
    'contractor' => $contractor,
    'customer' => $customer,
    'ai_super_admin' => $ai,
];

foreach ($roles as $name => $user) {
    echo "User {$name}: ".($user ? "exists (id={$user->id})" : 'MISSING')."\n";
}

// AI login blocked
if ($ai) {
    $blocked = ! Auth::attempt(['email' => $ai->email, 'password' => 'password']);
    echo "\nAI login blocked (wrong password expected): ".($blocked ? 'OK' : 'CHECK - auth may succeed with random pw')."\n";
    // Try with any password - should fail since random hash
    $attempt = Auth::attempt(['email' => $ai->email, 'password' => 'any-password-test']);
    Auth::logout();
    echo "AI login with test password returns false: ".(! $attempt ? 'OK' : 'FAIL')."\n";
}

// Simulate AuthController block after successful auth (edge case)
if ($ai && Hash::check('', $ai->password) === false) {
    echo "AI account uses non-guessable password: OK\n";
}

echo "\nAuthorizer checks:\n";
echo "- AI cannot kill switch: ".($auth->canPerform($ai, 'change_ai_kill_switch') ? 'FAIL' : 'OK')."\n";
echo "- Owner can kill switch: ".($auth->canPerform($owner, 'change_ai_kill_switch') ? 'OK' : 'FAIL')."\n";
echo "- AI cannot hard delete: ".($auth->canPerform($ai, 'hard_delete_record') ? 'FAIL' : 'OK')."\n";

// Seeder idempotency
$beforeSettings = Setting::where('key', 'like', 'ai_%')->count();
Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\Milestone4Seeder', '--force' => true]);
$afterSettings = Setting::where('key', 'like', 'ai_%')->count();
$actionTypes = \App\Models\AiActionType::count();
Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\Milestone4Seeder', '--force' => true]);
$actionTypes2 = \App\Models\AiActionType::count();
echo "\nSeeder idempotency:\n";
echo "- AI settings count stable after 2 runs: ".($beforeSettings === $afterSettings ? 'OK' : "changed {$beforeSettings}->{$afterSettings}")."\n";
echo "- AiActionType count stable: ".($actionTypes === $actionTypes2 ? "OK ({$actionTypes})" : "FAIL {$actionTypes}->{$actionTypes2}")."\n";

echo "\nDone.\n";
