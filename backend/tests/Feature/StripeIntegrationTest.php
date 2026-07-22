<?php

namespace Tests\Feature;

use App\Models\AiActionLog;
use App\Models\Invoice;
use App\Models\Job;
use App\Models\Payout;
use App\Models\Quote;
use App\Models\Setting;
use App\Models\StripeWebhookEvent;
use App\Models\User;
use App\Services\Accounting\InvoiceService;
use App\Services\Payments\StripePaymentProvider;
use Database\Seeders\Milestone4Seeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class StripeIntegrationTest extends TestCase
{
    use DatabaseTransactions;

    public function createApplication()
    {
        $app = parent::createApplication();
        $app['config']->set('database.default', 'mysql');
        $app['config']->set('database.connections.mysql.database', 'hsop_job_command');
        $app['config']->set('payment.provider', 'stripe');
        $app['config']->set('payment.stripe.secret', 'sk_test_dummy_for_unit');
        $app['config']->set('payment.stripe.publishable', 'pk_test_dummy');
        $app['config']->set('payment.stripe.webhook_secret', 'whsec_test_secret');

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
        Setting::set('invoice_number_next', (string) random_int(500, 800));
        Setting::set('payout_schedule_business_days', '2');
    }

    private function makeInvoiceContext(): array
    {
        $pm = User::create([
            'name' => 'Stripe PM', 'email' => 'stripe-pm-'.uniqid().'@test.local',
            'password' => bcrypt('password'), 'role' => 'pm', 'status' => 'active',
        ]);
        $contractor = User::create([
            'name' => 'Stripe Con', 'email' => 'stripe-con-'.uniqid().'@test.local',
            'password' => bcrypt('password'), 'role' => 'contractor', 'status' => 'active',
        ]);
        $customer = User::create([
            'name' => 'Stripe Cust', 'email' => 'stripe-cust-'.uniqid().'@test.local',
            'password' => bcrypt('password'), 'role' => 'customer', 'status' => 'active', 'phone' => '6045550111',
        ]);

        $job = Job::create([
            'customer_id' => $customer->id,
            'contractor_id' => $contractor->id,
            'pm_id' => $pm->id,
            'address' => '500 Stripe Test Ave',
            'service_category' => 'drywall_paint',
            'status' => 'payment_pending',
            'scope_of_work' => 'Stripe test',
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
            'scope_of_work' => 'Stripe test',
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

        return compact('pm', 'contractor', 'customer', 'job', 'invoice');
    }

    public function test_provider_binding_is_stripe(): void
    {
        $this->assertInstanceOf(StripePaymentProvider::class, app(\App\Contracts\PaymentProviderInterface::class));
    }

    public function test_webhook_rejects_invalid_signature(): void
    {
        $res = $this->call(
            'POST',
            '/api/stripe/webhook',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_Stripe-Signature' => 't=1,v1=invalid',
            ],
            json_encode(['id' => 'evt_test', 'type' => 'checkout.session.completed'])
        );

        $this->assertSame(400, $res->getStatusCode());
    }

    public function test_checkout_completed_marks_paid_and_is_idempotent(): void
    {
        $ctx = $this->makeInvoiceContext();
        $invoice = $ctx['invoice'];
        /** @var StripePaymentProvider $stripe */
        $stripe = app(StripePaymentProvider::class);

        $event = [
            'id' => 'evt_test_'.uniqid(),
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => 'cs_test_'.uniqid(),
                    'payment_status' => 'paid',
                    'status' => 'complete',
                    'amount_total' => 105000,
                    'payment_intent' => 'pi_test_'.uniqid(),
                    'metadata' => ['invoice_id' => (string) $invoice->id],
                    'client_reference_id' => (string) $invoice->id,
                ],
            ],
        ];

        $first = $stripe->handleWebhook($event);
        $this->assertTrue($first['handled']);
        $this->assertSame('paid', $first['status']);
        $this->assertSame('paid', $invoice->fresh()->status);

        $second = $stripe->handleWebhook($event);
        $this->assertTrue($second['handled']);
        $this->assertSame('duplicate', $second['status']);
        $this->assertSame(1, StripeWebhookEvent::where('event_id', $event['id'])->count());

        // Payouts should be scheduled via eligibility
        $this->assertTrue(
            Payout::where('job_id', $ctx['job']->id)->where('status', 'scheduled')->exists()
        );
    }

    public function test_payment_failed_path(): void
    {
        $ctx = $this->makeInvoiceContext();
        $invoice = $ctx['invoice'];
        $stripe = app(StripePaymentProvider::class);

        $event = [
            'id' => 'evt_fail_'.uniqid(),
            'type' => 'payment_intent.payment_failed',
            'data' => [
                'object' => [
                    'id' => 'pi_fail_'.uniqid(),
                    'metadata' => ['invoice_id' => (string) $invoice->id],
                ],
            ],
        ];

        $result = $stripe->handleWebhook($event);
        $this->assertTrue($result['handled']);
        $this->assertSame('payment_failed', $result['status']);
        $this->assertSame('payment_failed', $invoice->fresh()->status);
    }

    public function test_dispute_holds_payouts(): void
    {
        $ctx = $this->makeInvoiceContext();
        $invoice = $ctx['invoice'];
        $invoice->update([
            'status' => 'paid',
            'amount_paid' => $invoice->amount,
            'balance' => 0,
            'stripe_payment_intent_id' => 'pi_dispute_test',
        ]);
        Payout::create([
            'job_id' => $ctx['job']->id,
            'contractor_id' => $ctx['contractor']->id,
            'payout_type' => 'contractor',
            'split_type' => 'contractor',
            'payout_amount' => 800,
            'status' => 'scheduled',
        ]);

        $stripe = app(StripePaymentProvider::class);
        $result = $stripe->handleWebhook([
            'id' => 'evt_disp_'.uniqid(),
            'type' => 'charge.dispute.created',
            'data' => [
                'object' => [
                    'id' => 'dp_test',
                    'payment_intent' => 'pi_dispute_test',
                ],
            ],
        ]);

        $this->assertTrue($result['handled']);
        $this->assertSame('disputed', $invoice->fresh()->status);
        $this->assertSame('on_hold', Payout::where('job_id', $ctx['job']->id)->value('status'));
    }

    public function test_refund_holds_company_platform_retain_paid_payout(): void
    {
        $ctx = $this->makeInvoiceContext();
        $invoice = $ctx['invoice'];
        $invoice->update([
            'status' => 'paid',
            'amount_paid' => $invoice->amount,
            'balance' => 0,
            'stripe_payment_intent_id' => 'pi_refund_company_test',
        ]);
        Payout::create([
            'job_id' => $ctx['job']->id,
            'payout_type' => 'company',
            'split_type' => 'company',
            'payout_amount' => 100,
            'status' => 'paid',
            'paid_date' => now()->toDateString(),
            'stripe_transfer_id' => 'platform_retain_99',
        ]);

        $stripe = app(StripePaymentProvider::class);
        $result = $stripe->handleWebhook([
            'id' => 'evt_ref_'.uniqid(),
            'type' => 'charge.refunded',
            'data' => [
                'object' => [
                    'id' => 'ch_test',
                    'payment_intent' => 'pi_refund_company_test',
                ],
            ],
        ]);

        $this->assertTrue($result['handled']);
        $this->assertSame('refunded', $invoice->fresh()->status);
        $payout = Payout::where('job_id', $ctx['job']->id)->where('payout_type', 'company')->first();
        $this->assertSame('on_hold', $payout->status);
        $this->assertNull($payout->paid_date);
        $this->assertNull($payout->stripe_transfer_id);
    }

    public function test_account_updated_sets_payout_ready(): void
    {
        $contractor = User::create([
            'name' => 'Connect User', 'email' => 'connect-'.uniqid().'@test.local',
            'password' => bcrypt('password'), 'role' => 'contractor', 'status' => 'active',
            'stripe_account_id' => 'acct_test_ready',
        ]);

        $stripe = app(StripePaymentProvider::class);
        $result = $stripe->handleWebhook([
            'id' => 'evt_acct_'.uniqid(),
            'type' => 'account.updated',
            'data' => [
                'object' => [
                    'id' => 'acct_test_ready',
                    'charges_enabled' => true,
                    'payouts_enabled' => true,
                    'details_submitted' => true,
                    'requirements' => [
                        'currently_due' => [],
                        'past_due' => [],
                        'eventually_due' => [],
                    ],
                ],
            ],
        ]);

        $this->assertTrue($result['handled']);
        $contractor->refresh();
        $this->assertSame('complete', $contractor->stripe_onboarding_status);
        $this->assertTrue($contractor->stripe_payout_ready);
    }

    public function test_connect_sync_endpoint_requires_linked_account(): void
    {
        $pm = User::create([
            'name' => 'PM Sync', 'email' => 'pm-sync-'.uniqid().'@test.local',
            'password' => bcrypt('password'), 'role' => 'pm', 'status' => 'active',
        ]);
        $token = $pm->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/stripe/connect/sync')
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'No Stripe Connect account linked yet']);
    }

    public function test_company_transfer_marks_paid_without_stripe_api(): void
    {
        $ctx = $this->makeInvoiceContext();
        $payout = Payout::create([
            'job_id' => $ctx['job']->id,
            'payout_type' => 'company',
            'split_type' => 'company',
            'payout_amount' => 100,
            'status' => 'scheduled',
        ]);

        $stripe = app(StripePaymentProvider::class);
        $result = $stripe->createTransfer($payout);
        $this->assertSame('paid', $result['status']);
        $this->assertSame('paid', $payout->fresh()->status);
    }

    public function test_transfer_queues_when_payee_not_ready(): void
    {
        $ctx = $this->makeInvoiceContext();
        $payout = Payout::create([
            'job_id' => $ctx['job']->id,
            'contractor_id' => $ctx['contractor']->id,
            'payout_type' => 'contractor',
            'split_type' => 'contractor',
            'payout_amount' => 800,
            'status' => 'scheduled',
        ]);

        $stripe = app(StripePaymentProvider::class);
        $result = $stripe->createTransfer($payout);
        $this->assertSame('queued', $result['status']);
        $this->assertSame('queued', $payout->fresh()->status);
    }

    public function test_transfer_queues_cleanly_when_connect_disabled(): void
    {
        config(['payment.provider' => 'stripe']);
        $this->assertInstanceOf(StripePaymentProvider::class, app(\App\Contracts\PaymentProviderInterface::class));

        $contractor = User::create([
            'name' => 'Blocked Connect', 'email' => 'blocked-'.uniqid().'@test.local',
            'password' => bcrypt('password'), 'role' => 'contractor', 'status' => 'active',
            'stripe_account_id' => 'acct_1FakeConnectBlocked',
            'stripe_onboarding_status' => 'complete',
            'stripe_payout_ready' => true,
        ]);
        $job = Job::create([
            'customer_id' => $contractor->id,
            'contractor_id' => $contractor->id,
            'address' => 'Blocked Connect Ave',
            'service_category' => 'drywall_paint',
            'status' => 'paid',
            'customer_accepted_completion_at' => now(),
        ]);
        $payout = Payout::create([
            'job_id' => $job->id,
            'contractor_id' => $contractor->id,
            'payout_type' => 'contractor',
            'split_type' => 'contractor',
            'payout_amount' => 50,
            'status' => 'scheduled',
            'scheduled_for' => now()->toDateString(),
        ]);

        $result = app(StripePaymentProvider::class)->createTransfer($payout->fresh());
        $this->assertSame('queued', $result['status']);
        $this->assertSame('queued', $payout->fresh()->status);
        $this->assertTrue(
            AiActionLog::where('trigger_event', 'payout_transfer_deferred')->where('decision', 'queued')->exists()
        );
    }

    public function test_secret_key_not_leaked_in_payment_details(): void
    {
        $ctx = $this->makeInvoiceContext();
        $owner = User::where('role', 'owner')->first() ?: User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner, 'sanctum');

        $res = $this->getJson('/api/jobs/'.$ctx['job']->id.'/payment-details');
        $res->assertOk();
        $json = $res->json();
        $encoded = json_encode($json);
        $this->assertStringNotContainsString('sk_test_dummy_for_unit', $encoded);
        $this->assertStringNotContainsString('whsec_test_secret', $encoded);
        $this->assertSame('pk_test_dummy', $json['stripe_publishable_key']);
    }
}
