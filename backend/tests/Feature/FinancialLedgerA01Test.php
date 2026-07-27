<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Job;
use App\Models\Payout;
use App\Models\PayoutEvent;
use App\Models\Quote;
use App\Models\User;
use App\Services\Accounting\InvoicePaymentService;
use App\Services\Accounting\InvoiceService;
use App\Services\Finance\FinancialLedgerService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Audit A-01 / A-26 / A-28 — unified financial ledger.
 */
class FinancialLedgerA01Test extends TestCase
{
    use DatabaseTransactions;

    public function createApplication()
    {
        $app = parent::createApplication();
        $app['config']->set('database.default', 'mysql');
        $app['config']->set('database.connections.mysql.database', 'hsop_job_command');

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (! Schema::hasTable('financial_ledger_entries')) {
            $this->artisan('migrate', [
                '--path' => 'database/migrations/2026_07_27_000005_financial_ledger_a01.php',
                '--force' => true,
            ]);
        }
    }

    private function owner(): User
    {
        return User::create([
            'name' => 'Ledger Owner',
            'email' => 'ledger-owner-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
            'role' => 'owner',
            'status' => 'active',
        ]);
    }

    private function customer(): User
    {
        return User::create([
            'name' => 'Ledger Cust',
            'email' => 'ledger-cust-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
            'role' => 'customer',
            'status' => 'active',
        ]);
    }

    private function makeJobWithQuote(float $contractorPrice, float $markup, bool $test = false): array
    {
        $customer = $this->customer();
        $job = Job::create([
            'customer_id' => $customer->id,
            'status' => 'quote_approved',
            'address' => '100 Ledger St',
            'is_test_data' => $test,
        ]);
        $subtotal = $contractorPrice + $markup;
        $quote = Quote::create([
            'job_id' => $job->id,
            'customer_id' => $customer->id,
            'quote_number' => 'Q-LED-'.uniqid(),
            'status' => 'approved',
            'accepted_at' => now(),
            'contractor_base_price' => $contractorPrice,
            'customer_price_before_gst' => $subtotal,
            'subtotal' => $subtotal,
            'hsop_markup' => $markup,
            'pm_amount' => round($markup * 0.5, 2),
            'company_amount' => round($markup * 0.5, 2),
            'gst' => round($subtotal * 0.05, 2),
            'customer_total' => round($subtotal * 1.05, 2),
        ]);
        if ($test) {
            $job->forceFill(['is_test_data' => true])->save();
            $quote->forceFill(['is_test_data' => true])->save();
        }

        return compact('customer', 'job', 'quote');
    }

    public function test_1_lifecycle_dashboard_reports_accounting_agree(): void
    {
        $owner = $this->owner();
        ['job' => $job, 'quote' => $quote] = $this->makeJobWithQuote(8000, 2000);

        $invoice = app(InvoiceService::class)->createFromJob($job);
        $this->assertGreaterThan(0, (float) $invoice->balance);

        // Partial then full payment
        $partial = round((float) $invoice->amount / 2, 2);
        app(InvoicePaymentService::class)->markPaid($invoice, ['amount' => $partial, 'payment_method' => 'e_transfer']);
        $invoice = $invoice->fresh();
        $this->assertSame('partially_paid', $invoice->status);

        app(InvoicePaymentService::class)->markPaid($invoice->fresh(), [
            'amount' => (float) $invoice->fresh()->balance,
            'payment_method' => 'e_transfer',
        ]);
        $invoice = $invoice->fresh();
        $this->assertSame('paid', $invoice->status);

        Sanctum::actingAs($owner);
        $dash = $this->getJson('/api/dashboard/admin/kpis')->assertOk()->json();
        $acct = $this->getJson('/api/accounting/dashboard')->assertOk()->json();
        $reports = $this->getJson('/api/reports/profit-breakdown')->assertOk()->json();

        $this->assertEqualsWithDelta($dash['projected_profit_all_time'], $reports['projected_profit'], 0.05);
        $this->assertEqualsWithDelta($dash['realized_profit_all_time'], $acct['realized_profit'], 0.05);
        $this->assertEqualsWithDelta($dash['total_collected_revenue'], $acct['gross_revenue'], 0.05);
        $this->assertSame('Projected Profit', $dash['financial_labels']['projected_profit']);
    }

    public function test_2_zero_contractor_price_excluded_from_projected_profit(): void
    {
        ['quote' => $bad] = $this->makeJobWithQuote(0, 5000);
        ['quote' => $good] = $this->makeJobWithQuote(1000, 250);

        $summary = app(FinancialLedgerService::class)->summary();
        $this->assertGreaterThanOrEqual(1, $summary['incomplete_cost_quote_count']);
        $this->assertTrue(
            collect($summary['incomplete_cost_quotes'])->contains(fn ($q) => (int) $q['id'] === $bad->id)
        );
        $projected = app(FinancialLedgerService::class)->drilldown('projected_profit');
        $ids = collect($projected['records'])->pluck('id');
        $this->assertTrue($ids->contains($good->id));
        $this->assertFalse($ids->contains($bad->id), 'Zero contractor_price quote must not contribute to projected profit');
    }

    public function test_3_unpaid_invoice_appears_in_accounts_receivable(): void
    {
        ['job' => $job] = $this->makeJobWithQuote(10000, 1733.75);
        $invoice = app(InvoiceService::class)->createFromJob($job, [
            'subtotal' => 11733.75,
            'gst' => 0,
            'amount' => 11733.75,
            'balance' => 11733.75,
        ]);

        $summary = app(FinancialLedgerService::class)->summary();
        $this->assertGreaterThanOrEqual(11733.75, $summary['accounts_receivable']);
        $drill = app(FinancialLedgerService::class)->drilldown('accounts_receivable');
        $this->assertTrue(collect($drill['records'])->contains(fn ($r) => (int) $r['id'] === $invoice->id));
        $this->assertGreaterThan(0, $summary['counts']['unpaid_invoices']);
    }

    public function test_4_payout_allocations_reconcile_to_customer_payment_ex_gst(): void
    {
        $owner = $this->owner();
        $contractor = User::create([
            'name' => 'Pay Con', 'email' => 'pay-con-'.uniqid().'@test.local',
            'password' => bcrypt('password'), 'role' => 'contractor', 'status' => 'active',
        ]);
        $pm = User::create([
            'name' => 'Pay PM', 'email' => 'pay-pm-'.uniqid().'@test.local',
            'password' => bcrypt('password'), 'role' => 'pm', 'status' => 'active',
        ]);

        ['job' => $job] = $this->makeJobWithQuote(800, 200);
        $job->update([
            'contractor_id' => $contractor->id,
            'pm_id' => $pm->id,
            'customer_accepted_completion_at' => now(),
            'status' => 'completed',
        ]);
        $invoice = app(InvoiceService::class)->createFromJob($job);
        app(InvoicePaymentService::class)->markPaid($invoice, [
            'amount' => (float) $invoice->amount,
            'payment_method' => 'e_transfer',
        ]);

        // Use eligibility-created rows (A-28) — mark paid; do not duplicate
        $payouts = Payout::where('job_id', $job->id)->get();
        $this->assertGreaterThanOrEqual(3, $payouts->count());
        foreach ($payouts as $p) {
            $p->update([
                'status' => 'paid',
                'paid_date' => now(),
                'stripe_transfer_id' => ($p->split_type ?: $p->payout_type) === 'company'
                    ? 'platform_retain_test'
                    : ($p->stripe_transfer_id ?: 'tr_test_'.$p->id),
            ]);
        }

        $recon = app(FinancialLedgerService::class)->payoutReconciliationForJob($job->id);
        $this->assertTrue($recon['reconciles_to_payment_ex_gst'], json_encode($recon));
        $this->assertSame('Company / Platform', collect($recon['allocations'])->firstWhere('split_type', 'company')['recipient_label']);
        $this->assertNotEmpty(collect($recon['allocations'])->firstWhere('status', 'paid')['stripe_transfer_id'] ?? null);

        Sanctum::actingAs($owner);
        $this->getJson('/api/ledger/payouts/job/'.$job->id)->assertOk()
            ->assertJsonPath('reconciles_to_payment_ex_gst', true);
    }

    public function test_5_dashboard_card_drilldown_matches_metric(): void
    {
        ['job' => $job] = $this->makeJobWithQuote(500, 100);
        app(InvoiceService::class)->createFromJob($job);

        Sanctum::actingAs($this->owner());
        $dash = $this->getJson('/api/dashboard/admin/kpis')->assertOk()->json();
        $drill = $this->getJson('/api/ledger/drilldown?metric=accounts_receivable')->assertOk()->json();
        $this->assertEqualsWithDelta($dash['accounts_receivable'], $drill['total'], 0.05);
        $this->assertNotEmpty($drill['records']);
        $this->assertArrayHasKey('refreshed_at', $drill);
    }

    public function test_6_hold_and_release_write_audit_events(): void
    {
        $owner = $this->owner();
        ['job' => $job] = $this->makeJobWithQuote(500, 100);
        $payout = Payout::create([
            'job_id' => $job->id,
            'payout_type' => 'contractor',
            'split_type' => 'contractor',
            'payout_amount' => 500,
            'status' => 'ready_for_payout',
        ]);

        Sanctum::actingAs($owner);
        $this->putJson("/api/payouts/{$payout->id}/hold", ['notes' => 'Fraud check'])->assertOk();
        $this->assertSame('on_hold', $payout->fresh()->status);
        $this->assertTrue(PayoutEvent::where('payout_id', $payout->id)->where('event_type', 'held')->exists());

        $this->putJson("/api/payouts/{$payout->id}/release")->assertOk();
        $this->assertSame('ready_for_payout', $payout->fresh()->status);
        $this->assertTrue(PayoutEvent::where('payout_id', $payout->id)->where('event_type', 'released')->exists());
    }

    public function test_7_test_data_excluded_from_ledger_totals(): void
    {
        ['quote' => $testQuote] = $this->makeJobWithQuote(9000, 1000, true);
        ['job' => $job, 'quote' => $realQuote] = $this->makeJobWithQuote(100, 20, false);
        $inv = app(InvoiceService::class)->createFromJob($job);
        app(InvoicePaymentService::class)->markPaid($inv, ['amount' => (float) $inv->amount, 'payment_method' => 'e_transfer']);

        $projected = app(FinancialLedgerService::class)->drilldown('projected_profit');
        $ids = collect($projected['records'])->pluck('id');
        $this->assertTrue($ids->contains($realQuote->id));
        $this->assertFalse($ids->contains($testQuote->id), 'is_test_data quote must be excluded from ledger');
    }

    public function test_8_reports_no_longer_return_placeholder_only_payload(): void
    {
        Sanctum::actingAs($this->owner());
        $res = $this->getJson('/api/reports/profit-breakdown')->assertOk()->json();
        $this->assertArrayHasKey('revenue_jobs_breakdown', $res);
        $this->assertArrayHasKey('projected_profit', $res);
        $this->assertArrayHasKey('realized_profit', $res);
        $this->assertArrayHasKey('incomplete_cost_quotes', $res);
        $this->assertSame('Projected Profit', $res['labels']['projected_profit']);
    }
}
