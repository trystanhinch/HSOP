<?php
/**
 * API verification: security, search filters, dashboard, completion workflow, settings UI.
 */
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Invoice;
use App\Models\Job;
use App\Models\User;
use Illuminate\Support\Facades\Http;

$base = 'http://localhost:8000/api';
$results = [];

function login($base, $email) {
    $r = Http::post("$base/login", ['email' => $email, 'password' => 'password']);
    return $r->successful() ? $r->json('token') : null;
}

$sarah = login($base, 'sarah@example.com');
$contractor = login($base, 'contractor@hsop.com');
$pm = login($base, 'pm@hsop.com');
$admin = login($base, 'admin@hsop.com');

// Security
$job2 = Http::withToken($sarah)->get("$base/jobs/2");
$results['security_sarah_job2'] = $job2->status();

$job4 = Http::withToken($contractor)->get("$base/jobs/4");
$results['security_contractor_job4'] = $job4->status();

$badToken = Http::get("$base/quote/view/sarah-approved-tokenXXX");
$results['security_bad_quote'] = ['status' => $badToken->status(), 'body' => $badToken->json()];

// Dashboard API vs DB
$dash = Http::withToken($admin)->get("$base/dashboard/admin/kpis");
$results['dashboard_api'] = $dash->json();

// Search filters
$filters = [];
$allJobs = Http::withToken($admin)->get("$base/jobs")->json('data') ?? Http::withToken($admin)->get("$base/jobs")->json();
$filters['baseline_count'] = is_array($allJobs) ? count($allJobs) : 0;

$byStatus = Http::withToken($admin)->get("$base/jobs/search", ['status' => 'in_progress'])->json('data') ?? [];
$filters['status_in_progress'] = [
    'count' => count($byStatus),
    'all_match' => collect($byStatus)->every(fn ($j) => $j['status'] === 'in_progress'),
];

$byName = Http::withToken($admin)->get("$base/jobs/search", ['q' => 'Sarah'])->json('data') ?? [];
$filters['search_sarah'] = [
    'count' => count($byName),
    'has_sarah' => collect($byName)->contains(fn ($j) => str_contains(strtolower($j['customer']['name'] ?? ''), 'sarah') || str_contains(strtolower($j['address'] ?? ''), 'sarah')),
];

$byContractor = Http::withToken($admin)->get("$base/jobs/search", ['contractor_id' => 3])->json('data') ?? [];
$filters['contractor_3'] = [
    'count' => count($byContractor),
    'all_match' => collect($byContractor)->every(fn ($j) => ($j['contractor_id'] ?? $j['contractor']['id'] ?? null) == 3),
];

$byPm = Http::withToken($admin)->get("$base/jobs/search", ['pm_id' => 2])->json('data') ?? [];
$filters['pm_2'] = [
    'count' => count($byPm),
    'all_match' => collect($byPm)->every(fn ($j) => ($j['pm_id'] ?? $j['pm']['id'] ?? null) == 2),
];

$byDate = Http::withToken($admin)->get("$base/jobs/search", ['date_from' => '2026-06-01', 'date_to' => '2026-06-30'])->json('data') ?? [];
$filters['date_range'] = ['count' => count($byDate)];

$combo = Http::withToken($admin)->get("$base/jobs/search", ['status' => 'in_progress', 'contractor_id' => 3])->json('data') ?? [];
$filters['combo'] = [
    'count' => count($combo),
    'all_match' => collect($combo)->every(fn ($j) => $j['status'] === 'in_progress' && (($j['contractor_id'] ?? 3) == 3)),
];

$results['search_filters'] = $filters;

// Settings UI via API
$before = Http::withToken($admin)->get("$base/settings")->json('gst_rate');
Http::withToken($admin)->post("$base/settings", ['gst_rate' => '6']);
$after = Http::withToken($admin)->get("$base/settings")->json('gst_rate');
Http::withToken($admin)->post("$base/settings", ['gst_rate' => '5']);
$results['settings_ui_api'] = ['before' => $before, 'after_change' => $after, 'pass' => $after == '6' || $after == 6];

// Overdue test — create draft invoice
$job = Job::first();
$inv = Invoice::create([
    'company_id' => 1, 'job_id' => $job->id, 'customer_id' => $job->customer_id,
    'invoice_number' => 'INV-OVERDUE-TEST', 'amount' => 100, 'balance' => 100,
    'status' => 'awaiting_payment', 'due_date' => now()->subDays(5)->toDateString(),
]);
$results['overdue_test'] = ['is_overdue' => $inv->is_overdue, 'pass' => $inv->is_overdue === true];
$inv->delete();

// Completion workflow via API (simulates UI button clicks)
$wip = Job::where('status', 'in_progress')->where('contractor_id', 3)->first();
if ($wip) {
    $r1 = Http::withToken($contractor)->post("$base/jobs/{$wip->id}/mark-ready-for-review");
    $wip->refresh();
    $results['completion_ready'] = ['http' => $r1->status(), 'db_status' => $wip->status, 'pass' => $wip->status === 'ready_for_review'];

    $r2 = Http::withToken($admin)->post("$base/jobs/{$wip->id}/mark-complete");
    $wip->refresh();
    $results['completion_done'] = ['http' => $r2->status(), 'db_status' => $wip->status, 'completed_at' => (string) $wip->completed_at, 'pass' => $wip->status === 'completed' && $wip->completed_at !== null];
}

// Corrections path
$wip2 = Job::where('status', 'in_progress')->first();
if ($wip2) {
    $wip2->update(['status' => 'ready_for_review', 'ready_for_review_at' => now()]);
    $note = 'VERIFY CORRECTIONS '.time();
    $r3 = Http::withToken($admin)->post("$base/jobs/{$wip2->id}/request-corrections", ['corrections_notes' => $note]);
    $wip2->refresh();
    $results['corrections'] = ['http' => $r3->status(), 'note_match' => $wip2->corrections_notes === $note, 'status' => $wip2->status, 'pass' => $wip2->corrections_notes === $note && $wip2->status === 'corrections_required'];
}

// Activity log job 7
$act = Http::withToken($admin)->get("$base/jobs/7/activity-log")->json();
$results['activity_log_job7_count'] = is_array($act) ? count($act) : 0;
$results['activity_log_job7'] = $act;

echo json_encode($results, JSON_PRETTY_PRINT);
