<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "=== STEP 5-7: Site visit workflow ===\n";

$lead = \App\Models\Lead::whereNotNull('customer_id')->first();
if (! $lead) {
    echo "No lead with customer\n";
    exit(1);
}

// Ensure customer has E.164-ish phone for test (use lead phone)
$customer = \App\Models\User::find($lead->customer_id);
if ($customer && $lead->phone) {
    $customer->update(['phone' => $lead->phone]);
}

$pm = \App\Models\User::where('role', 'pm')->first();
$contractor = \App\Models\User::where('role', 'contractor')->first();
$token = $pm->createToken('verify-test')->plainTextToken;

$response = \Illuminate\Support\Facades\Http::withToken($token)
    ->post('http://127.0.0.1:8000/api/leads/'.$lead->id.'/schedule-site-visit', [
        'site_visit_date' => now()->addDays(3)->format('Y-m-d'),
        'site_visit_time' => '10:00',
        'site_visit_contractor_id' => $contractor->id,
        'site_visit_notes' => 'SMS verification workflow test',
    ]);

echo "Schedule API status: {$response->status()}\n";
if ($response->failed()) {
    echo "Body: {$response->body()}\n";
}

echo "\n=== SMS logs (latest 3) ===\n";
foreach (\App\Models\SmsLog::latest()->take(3)->get() as $log) {
    echo "  {$log->trigger_event} | {$log->to_phone} | {$log->status} | ".($log->error_message ?? '-')."\n";
}

echo "\n=== Email logs (latest 3) ===\n";
foreach (\App\Models\EmailLog::latest()->take(3)->get() as $log) {
    echo "  {$log->trigger_event} | {$log->to_email} | {$log->status} | ".($log->error_message ?? '-')."\n";
}

$siteVisit = \App\Models\SiteVisit::latest()->first();
if ($siteVisit) {
    echo "\n=== STEP 7: Site visit ===\n";
    echo "ID: {$siteVisit->id} | Lead: {$siteVisit->lead->contact_name} | Date: {$siteVisit->visit_date} | Contractor: {$siteVisit->contractor->name}\n";
}

$owner = \App\Models\User::where('role', 'owner')->first();
$ownerToken = $owner->createToken('verify-schedule')->plainTextToken;
$sched = \Illuminate\Support\Facades\Http::withToken($ownerToken)
    ->get('http://127.0.0.1:8000/api/schedule', ['month' => now()->addDays(3)->format('Y-m')]);
$data = $sched->json();
echo "Schedule items: all=".count($data['all'] ?? [])." site_visits=".count($data['site_visits'] ?? [])."\n";
if ($siteVisit) {
    $found = collect($data['site_visits'] ?? [])->firstWhere('lead_id', $siteVisit->lead_id);
    echo ($found ? '✓ Site visit on calendar' : '✗ Site visit NOT on calendar')."\n";
}

$leadFresh = \App\Models\Lead::latest()->first();
if ($leadFresh?->customer_portal_token) {
    echo "\n=== STEP 8: Portal ===\n";
    $portal = \Illuminate\Support\Facades\Http::get('http://127.0.0.1:8000/api/portal/'.$leadFresh->customer_portal_token);
    echo "Status: {$portal->status()}\n";
    $pd = $portal->json();
    echo "Lead: ".($pd['lead']['contact_name'] ?? 'MISSING')." | status: ".($pd['lead']['status'] ?? 'MISSING')."\n";
    $exposed = isset($pd['quote']['contractor_base_price']);
    echo ($exposed ? '✗ contractor price exposed' : '✓ contractor price hidden')."\n";
}

echo "\n=== STEP 9: Formula ===\n";
$quote = \App\Models\Quote::where('contractor_base_price', '>', 0)->latest()->first();
if ($quote) {
    $ratio = round($quote->customer_price_before_gst / $quote->contractor_base_price, 2);
    $pmPct = $quote->customer_price_before_gst > 0
        ? round($quote->pm_amount / $quote->customer_price_before_gst * 100, 1) : 0;
    echo "Ratio: {$ratio} (expect 1.25) | PM pct: {$pmPct}% (expect 10)\n";
}
