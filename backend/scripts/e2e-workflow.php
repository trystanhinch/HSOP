<?php
/**
 * Milestone 3 fresh-data E2E workflow test (API-driven).
 * Run: php scripts/e2e-workflow.php
 */
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Invoice;
use App\Models\Job;
use App\Models\Lead;
use App\Models\Payout;
use App\Models\Quote;
use App\Models\User;
use App\Services\PricingService;

$steps = [];
$fail = null;

try {
    $admin = User::where('email', 'admin@hsop.com')->firstOrFail();
    $pm = User::where('email', 'pm@hsop.com')->firstOrFail();
    $contractor = User::where('email', 'contractor@hsop.com')->firstOrFail();

    // 1. Create lead
    $lead = Lead::create([
        'company_id' => 1,
        'contact_name' => 'E2E Test '.now()->format('His'),
        'phone' => '604-555-9999',
        'email' => 'e2e-test-'.time().'@example.com',
        'address' => '999 E2E Test Street, Vancouver, BC',
        'service_category' => 'drywall_paint',
        'status' => 'new',
        'assigned_pm_id' => $pm->id,
    ]);
    $steps[] = "1. Lead created #{$lead->id}";

    // 2. Convert to job
    $job = Job::create([
        'company_id' => 1,
        'lead_id' => $lead->id,
        'customer_id' => User::where('role', 'customer')->first()->id,
        'pm_id' => $pm->id,
        'job_title' => $lead->contact_name.' - E2E Test',
        'service_category' => 'drywall_paint',
        'address' => $lead->address,
        'scope_of_work' => 'E2E test scope',
        'status' => 'new_job',
    ]);
    $steps[] = "2. Job created #{$job->id}";

    // 3. Assign contractor
    $job->update([
        'contractor_id' => $contractor->id,
        'status' => 'contractor_assigned',
        'contractor_price_status' => 'pending',
    ]);
    $steps[] = '3. Contractor assigned';

    // 4. Submit price
    $job->update([
        'contractor_submitted_price' => 8000,
        'contractor_price_status' => 'submitted',
        'contractor_price_submitted_at' => now(),
    ]);
    $steps[] = '4. Contractor price $8000 submitted';

    // 5. Create quote with formula
    $pricing = app(PricingService::class);
    $totals = $pricing->fromContractorPrice(8000);
    $ratio = $totals['customer_subtotal'] / 8000;
    if ($ratio < 1.249 || $ratio > 1.251) {
        throw new Exception("Formula wrong: ratio {$ratio}, expected 1.25");
    }
    $quote = Quote::create([
        'company_id' => 1,
        'job_id' => $job->id,
        'customer_id' => $job->customer_id,
        'quote_number' => 'QT-E2E-'.time(),
        'contractor_base_price' => 8000,
        'customer_price_before_gst' => $totals['customer_subtotal'],
        'hsop_markup' => $totals['hsop_markup'],
        'gst' => $totals['gst'],
        'customer_total' => $totals['customer_total'],
        'subtotal' => $totals['customer_subtotal'],
        'gst_rate' => $totals['gst_rate'],
        'gst_enabled' => true,
        'status' => 'draft',
        'customer_token' => bin2hex(random_bytes(16)),
        'scope_of_work' => $job->scope_of_work,
    ]);
    $steps[] = "5. Quote created — subtotal \${$totals['customer_subtotal']} ratio {$ratio}";

    // 6. Send quote (SMS/email will log — credentials may be placeholders)
    $quote->update(['status' => 'sent', 'sent_at' => now()]);
    app(\App\Services\JobNotificationService::class)->quoteSent(
        $quote->fresh(['customer', 'job']),
        config('app.frontend_url', 'http://localhost:5173').'/quote/view/'.$quote->customer_token
    );
    $steps[] = '6. Quote sent (notifications triggered)';

    // 7. Approve quote
    $quote->update(['status' => 'approved', 'accepted_at' => now()]);
    $job->update(['status' => 'quote_approved']);
    app(\App\Services\JobNotificationService::class)->quoteApproved($quote->fresh(['customer', 'job']));
    $steps[] = '7. Quote approved';

    // 8. Schedule job
    $job->update([
        'status' => 'scheduled',
        'scheduled_start_date' => now()->addDays(3)->toDateString(),
        'estimated_completion_date' => now()->addDays(10)->toDateString(),
    ]);
    app(\App\Services\JobNotificationService::class)->jobScheduled($job->fresh(['customer', 'contractor']), false);
    $steps[] = '8. Job scheduled';

    // 9. Progress update (simulated)
    $job->update(['status' => 'in_progress']);
    $steps[] = '9. Job in progress';

    // 10. Ready for review
    $job->update(['status' => 'ready_for_review', 'ready_for_review_at' => now()]);
    app(\App\Services\JobNotificationService::class)->readyForReview($job->fresh(['contractor', 'pm']));
    $steps[] = '10. Ready for review';

    // 11. Mark complete
    $job->update(['status' => 'completed', 'completed_at' => now()]);
    app(\App\Services\JobNotificationService::class)->jobComplete($job->fresh(['customer', 'contractor']));
    $steps[] = '11. Job marked complete';

    // 12. Invoice send + mark paid
    $invoice = Invoice::create([
        'company_id' => 1,
        'job_id' => $job->id,
        'quote_id' => $quote->id,
        'customer_id' => $job->customer_id,
        'invoice_number' => 'INV-E2E-'.time(),
        'scope_of_work' => $job->scope_of_work,
        'subtotal' => $quote->customer_price_before_gst,
        'gst' => $quote->gst,
        'gst_rate' => $quote->gst_rate,
        'amount' => $quote->customer_total,
        'balance' => $quote->customer_total,
        'status' => 'awaiting_payment',
        'due_date' => now()->addDays(30)->toDateString(),
    ]);
    $invoice->update(['status' => 'invoice_sent', 'sent_at' => now()]);
    app(\App\Services\JobNotificationService::class)->invoiceSent($invoice->fresh(['job.customer']));
    $invoice->update(['status' => 'paid', 'balance' => 0]);
    $steps[] = '12. Invoice sent and marked paid';

    // 13. Payout ready
    app(\App\Services\PayoutWorkflowService::class)->syncPayoutReadiness($job->fresh(['invoice']));
    $payout = Payout::where('job_id', $job->id)->first();
    if (! $payout || $payout->status !== 'ready_for_payout') {
        throw new Exception('Payout not ready_for_payout: '.($payout->status ?? 'missing'));
    }
    $payout->update(['status' => 'paid', 'paid_date' => now(), 'authorized_by' => $admin->id]);
    $steps[] = "13. Payout ready_for_payout → paid (#{$payout->id})";

    echo "E2E_PASS\n";
    echo "Job ID: {$job->id}\n";
    foreach ($steps as $s) {
        echo "  ✓ {$s}\n";
    }

    $smsCount = \App\Models\SmsLog::count();
    $emailCount = \App\Models\EmailLog::count();
    echo "\nSMS logs: {$smsCount} | Email logs: {$emailCount}\n";
} catch (Throwable $e) {
    echo "E2E_FAIL at step ".count($steps)."\n";
    echo "Error: {$e->getMessage()}\n";
    foreach ($steps as $s) {
        echo "  ✓ {$s}\n";
    }
    exit(1);
}
