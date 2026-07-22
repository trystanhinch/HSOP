<?php

namespace Tests\Feature;

use App\Contracts\PaymentProviderInterface;
use App\Models\AiActionLog;
use App\Models\Invoice;
use App\Models\Job;
use App\Models\Payout;
use App\Models\Quote;
use App\Models\Setting;
use App\Models\User;
use App\Services\Accounting\BusinessDayCalculator;
use App\Services\Accounting\InvoiceService;
use App\Services\PayoutEligibilityService;
use Carbon\Carbon;
use Database\Seeders\Milestone4Seeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class Phase4AccountingTest extends TestCase
{
    use DatabaseTransactions;

    public function createApplication()
    {
        $app = parent::createApplication();
        $app['config']->set('database.default', 'mysql');
        $app['config']->set('database.connections.mysql.database', 'hsop_job_command');
        $app['config']->set('payment.provider', 'mock');

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(Milestone4Seeder::class);
        Setting::set('gst_rate', '5');
        Setting::set('split_contractor_pct', '80');
        Setting::set('split_pm_pct', '10');
        Setting::set('split_company_pct', '10');
        Setting::set('invoice_number_format', 'INV-{XXXX}');
        Setting::set('invoice_number_next', '100');
        Setting::set('payout_schedule_business_days', '2');
    }

    public function test_business_day_calculator_skips_weekend(): void
    {
        // Friday + 2 business days = Tuesday
        $from = Carbon::parse('2026-07-17'); // Friday
        $result = app(BusinessDayCalculator::class)->addBusinessDays($from, 2);
        $this->assertSame('2026-07-21', $result->toDateString());
    }

    public function test_invoice_creation_gst_and_mock_payment_eligibility(): void
    {
        $owner = User::where('role', 'owner')->first() ?: User::factory()->create(['role' => 'owner']);
        $pm = User::where('role', 'pm')->first() ?: User::create([
            'name' => 'Phase4 PM', 'email' => 'phase4-pm-'.uniqid().'@test.local',
            'password' => bcrypt('password'), 'role' => 'pm', 'status' => 'active',
        ]);
        $contractor = User::where('role', 'contractor')->first() ?: User::create([
            'name' => 'Phase4 Contractor', 'email' => 'phase4-con-'.uniqid().'@test.local',
            'password' => bcrypt('password'), 'role' => 'contractor', 'status' => 'active',
        ]);
        $customer = User::create([
            'name' => 'Phase4 Customer', 'email' => 'phase4-cust-'.uniqid().'@test.local',
            'password' => bcrypt('password'), 'role' => 'customer', 'status' => 'active',
        ]);

        $job = Job::create([
            'customer_id' => $customer->id,
            'contractor_id' => $contractor->id,
            'pm_id' => $pm->id,
            'address' => '100 Phase4 Test St',
            'service_category' => 'drywall_paint',
            'status' => 'payment_pending',
            'scope_of_work' => 'Drywall repair and paint',
            'contractor_submitted_price' => 800,
            'split_contractor_pct' => 80,
            'split_pm_pct' => 10,
            'split_company_pct' => 10,
            'customer_accepted_completion_at' => now(),
        ]);

        Quote::create([
            'job_id' => $job->id,
            'customer_id' => $customer->id,
            'status' => 'approved',
            'scope_of_work' => 'Drywall repair and paint',
            'contractor_base_price' => 800,
            'customer_price_before_gst' => 1000,
            'gst_rate' => 5,
            'gst' => 50,
            'customer_total' => 1050,
            'contractor_pct' => 80,
            'pm_pct' => 10,
            'company_pct' => 10,
            'pm_amount' => 100,
            'company_amount' => 100,
        ]);

        $invoice = app(InvoiceService::class)->createFromJob($job->fresh(['quote', 'lead.companySource']));

        $this->assertSame(1000.0, (float) $invoice->subtotal);
        $this->assertSame(50.0, (float) $invoice->gst);
        $this->assertSame(1050.0, (float) $invoice->amount);
        $this->assertSame(1050.0, (float) $invoice->balance);
        $this->assertStringStartsWith('INV-', $invoice->invoice_number);

        // Before payment → waiting_for_payment
        $pre = app(PayoutEligibilityService::class)->evaluateForJob($job->fresh([
            'invoice', 'quote', 'revisionRequests', 'contractor', 'pm',
        ]));
        $this->assertFalse($pre['eligible']);
        $this->assertSame('waiting_for_payment', $pre['status']);

        /** @var PaymentProviderInterface $provider */
        $provider = app(PaymentProviderInterface::class);
        $this->assertSame('mock', $provider->createPaymentLink($invoice)['provider']);

        $paid = $provider->handleWebhook([
            'event' => 'payment_succeeded',
            'invoice_id' => $invoice->id,
            'amount' => $invoice->balance,
        ]);
        $this->assertTrue($paid['handled']);
        $this->assertSame('paid', $paid['status']);

        $invoice->refresh();
        $this->assertSame('paid', $invoice->status);
        $this->assertEquals(0.0, (float) $invoice->balance);

        $contractorPayout = Payout::where('job_id', $job->id)->where('payout_type', 'contractor')->first();
        $pmPayout = Payout::where('job_id', $job->id)->where('payout_type', 'pm')->first();
        $companyPayout = Payout::where('job_id', $job->id)->where('payout_type', 'company')->first();

        $this->assertNotNull($contractorPayout);
        $this->assertSame('scheduled', $contractorPayout->status);
        $this->assertEquals(800.0, (float) $contractorPayout->payout_amount);
        $this->assertEquals(100.0, (float) $pmPayout->payout_amount);
        $this->assertEquals(100.0, (float) $companyPayout->payout_amount);

        $expectedSchedule = app(BusinessDayCalculator::class)
            ->addBusinessDays($contractorPayout->eligible_at, 2)
            ->toDateString();
        $this->assertSame($expectedSchedule, $contractorPayout->scheduled_for?->format('Y-m-d')
            ?? Carbon::parse($contractorPayout->scheduled_for)->toDateString());

        $this->assertTrue(
            AiActionLog::where('trigger_event', 'payout_eligibility_check')->where('decision', 'eligible')->exists()
        );

        // Accounting dashboard math (totals may include other local paid invoices)
        $this->actingAs($owner, 'sanctum');
        $dash = $this->getJson('/api/accounting/dashboard');
        $dash->assertOk();
        $this->assertGreaterThanOrEqual(1000, (float) $dash->json('gross_revenue'));
        $this->assertGreaterThanOrEqual(50, (float) $dash->json('gst_collected'));
        $dash->assertJsonPath('payment_provider', 'mock');

        // CSV export
        $csv = $this->get('/api/accounting/export?type=invoices');
        $csv->assertOk();
        $this->assertStringContainsString('invoice_number', $csv->streamedContent());

        // PDF
        $pdf = $this->get("/api/invoices/{$invoice->id}/pdf");
        $pdf->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'));

        // Mock transfer
        $xfer = $this->postJson("/api/accounting/payouts/{$contractorPayout->id}/mock-transfer");
        $xfer->assertOk();
        $this->assertSame('paid', $contractorPayout->fresh()->status);
        $this->assertNotEmpty($contractorPayout->fresh()->stripe_transfer_id);
    }

    public function test_payment_provider_interface_is_swappable(): void
    {
        $this->assertInstanceOf(
            \App\Services\Payments\MockPaymentProvider::class,
            app(PaymentProviderInterface::class)
        );
        $this->assertTrue(array_key_exists('stripe', config('payment.providers')));
        $this->assertSame(
            \App\Services\Payments\StripePaymentProvider::class,
            config('payment.providers.stripe')
        );
        // Real Stripe class is reserved for later wiring — config documents the swap point.
        $this->assertSame('mock', config('payment.provider'));
    }
}
