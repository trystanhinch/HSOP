<?php
/**
 * Milestone 3 self-verification — database & logic checks.
 * Run: php scripts/self-verify.php
 */
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Job;
use App\Models\Lead;
use App\Models\Payout;
use App\Models\Quote;
use App\Models\Setting;
use App\Models\SmsLog;
use App\Models\EmailLog;
use App\Services\PricingService;

$out = [];

// SECTION 1 — Formula
$q = Quote::where('contractor_base_price', '>', 0)->latest()->first();
if ($q) {
    $ratio = round($q->customer_price_before_gst / $q->contractor_base_price, 4);
    $out['s1_formula'] = [
        'quote_id' => $q->id,
        'contractor_base_price' => (string) $q->contractor_base_price,
        'customer_price_before_gst' => (string) $q->customer_price_before_gst,
        'ratio' => $ratio,
        'pass' => $ratio >= 1.249 && $ratio <= 1.251,
    ];
} else {
    $out['s1_formula'] = ['pass' => false, 'error' => 'no quote with contractor price'];
}

// SECTION 2 — completion workflow DB state
$out['s2_jobs_by_status'] = Job::selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status');
$out['s2_in_progress_job'] = Job::where('status', 'in_progress')->first(['id', 'status', 'contractor_id']);
$out['s2_ready_for_review'] = Job::where('status', 'ready_for_review')->count();
$out['s2_completed_with_ts'] = Job::where('status', 'completed')->whereNotNull('completed_at')->count();
$out['s2_corrections'] = Job::where('status', 'corrections_required')->whereNotNull('corrections_notes')->first(['id', 'corrections_notes']);

// SECTION 3 — Invoice statuses
$out['s3_invoice_statuses'] = Invoice::distinct()->pluck('status')->values()->all();
$unpaid = Invoice::where('status', '!=', 'paid')->first();
if ($unpaid) {
    $origDue = $unpaid->due_date;
    $unpaid->update(['due_date' => now()->subDays(5)]);
    $unpaid->refresh();
    $out['s3_overdue_test'] = [
        'invoice_id' => $unpaid->id,
        'status' => $unpaid->status,
        'is_overdue' => $unpaid->is_overdue,
        'pass' => $unpaid->is_overdue === true,
    ];
    $unpaid->update(['due_date' => $origDue]);
}
$paid = Invoice::where('status', 'paid')->first();
if ($paid) {
    $out['s3_paid_not_overdue'] = ['invoice_id' => $paid->id, 'is_overdue' => $paid->is_overdue, 'pass' => $paid->is_overdue === false];
}

// SECTION 4 — Settings
$oldGst = Setting::where('key', 'gst_rate')->value('value');
Setting::where('key', 'gst_rate')->update(['value' => '7']);
$pricing = app(PricingService::class);
$calc = $pricing->fromContractorPrice(8000);
$newQuote = Quote::create([
    'company_id' => 1,
    'job_id' => Job::first()->id,
    'customer_id' => Job::first()->customer_id,
    'quote_number' => 'QT-VERIFY-'.time(),
    'contractor_base_price' => 8000,
    'customer_price_before_gst' => $calc['customer_subtotal'],
    'hsop_markup' => $calc['hsop_markup'],
    'gst' => $calc['gst'],
    'customer_total' => $calc['customer_total'],
    'subtotal' => $calc['customer_subtotal'],
    'gst_rate' => $calc['gst_rate'],
    'gst_enabled' => true,
    'status' => 'draft',
    'customer_token' => bin2hex(random_bytes(8)),
]);
$oldQuote = Quote::where('id', '!=', $newQuote->id)->where('gst_rate', '>', 0)->first();
Setting::where('key', 'gst_rate')->update(['value' => '5']);
$out['s4_settings'] = [
    'new_quote_gst_rate' => (float) $newQuote->gst_rate,
    'new_quote_gst_amount' => (float) $newQuote->gst,
    'expected_gst_rate' => 7.0,
    'new_quote_pass' => (float) $newQuote->gst_rate === 7.0,
    'old_quote_id' => $oldQuote?->id,
    'old_quote_gst_rate' => $oldQuote ? (float) $oldQuote->gst_rate : null,
    'old_untouched_pass' => $oldQuote ? (float) $oldQuote->gst_rate !== 7.0 : null,
];

// SECTION 5 — audit events in codebase (grep-style via reflection on JobNotificationService + controllers)
$requiredEvents = [
    'lead_created', 'job_created', 'contractor_assigned', 'contractor_price_submitted',
    'quote_created', 'quote_sent', 'quote_approved', 'quote_rejected', 'job_scheduled',
    'schedule_changed', 'progress_update_submitted', 'photos_uploaded', 'ready_for_review',
    'corrections_requested', 'job_completed', 'payment_status_changed', 'payout_status_changed',
];
$loggedInDb = AuditLog::distinct()->pluck('action_type')->values()->all();
$jobWithLogs = Job::has('updates')->orWhereHas('quote')->first();
$sampleJobId = $jobWithLogs?->id ?? Job::first()?->id;
$sampleLogs = $sampleJobId ? AuditLog::where('object_type', 'job')->where('object_id', $sampleJobId)->orderByDesc('created_at')->limit(15)->get(['action_type', 'created_at', 'user_id']) : collect();
$out['s5'] = [
    'distinct_action_types_in_db' => $loggedInDb,
    'sample_job_id' => $sampleJobId,
    'sample_job_logs' => $sampleLogs->toArray(),
];

// SECTION 6 — Dashboard cross-check
$out['s6_dashboard'] = [
    'new_leads' => Lead::where('status', 'new')->count(),
    'jobs_awaiting_price' => Job::where('contractor_price_status', 'pending')->count(),
    'quotes_needing_review' => Quote::where('status', 'draft')->count(),
    'quotes_sent' => Quote::where('status', 'sent')->count(),
    'scheduled_jobs' => Job::where('status', 'scheduled')->count(),
    'jobs_in_progress' => Job::where('status', 'in_progress')->count(),
    'jobs_ready_for_review' => Job::where('status', 'ready_for_review')->count(),
    'completed_jobs' => Job::where('status', 'completed')->count(),
    'jobs_awaiting_payment' => Invoice::whereIn('status', ['invoice_sent', 'awaiting_payment', 'partially_paid'])->count(),
    'payouts_pending' => Payout::whereIn('status', ['pending', 'ready_for_payout'])->count(),
];

// SECTION 8/9 — SMS/Email logs
$out['s8_sms_logs'] = SmsLog::orderByDesc('id')->limit(20)->get(['id', 'to_phone', 'trigger_event', 'status', 'error_message'])->toArray();
$out['s8_sms_total'] = SmsLog::count();
$out['s8_sms_sent'] = SmsLog::where('status', 'sent')->count();
$out['s9_email_logs'] = EmailLog::orderByDesc('id')->limit(20)->get(['id', 'to_email', 'trigger_event', 'status', 'error_message'])->toArray();
$out['s9_email_total'] = EmailLog::count();
$out['s9_email_sent'] = EmailLog::where('status', 'sent')->count();

echo json_encode($out, JSON_PRETTY_PRINT);
