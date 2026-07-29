<?php

namespace Tests\Feature;

use App\Contracts\PaymentProviderInterface;
use App\Models\Invoice;
use App\Models\Job;
use App\Models\Lead;
use App\Models\User;
use App\Services\Payments\MockPaymentProvider;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Completes hotfix 17f18ad / 8ef1fcc root-cause fix: opaque customer_portal_token
 * lookups must use withTestData() so ExcludeTestDataScope cannot 404 real or
 * test-flagged portal links for review + Stripe checkout.
 */
class PortalTokenReviewStripeRegressionTest extends TestCase
{
    use DatabaseTransactions;

    public function createApplication()
    {
        $app = parent::createApplication();
        $app['config']->set('database.default', 'mysql');
        $app['config']->set('database.connections.mysql.database', 'hsop_job_command');
        $app['config']->set('payment.provider', 'stripe');
        $app['config']->set('app.frontend_url', 'https://app.serviceop.ca');

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        // Bypass real Stripe while still satisfying portalCheckout's provider === stripe gate.
        $this->app->instance(PaymentProviderInterface::class, app(MockPaymentProvider::class));
    }

    /**
     * @return array{token: string, lead: Lead, job: Job}
     */
    private function makePortalJobContext(bool $isTestData): array
    {
        $suffix = substr(uniqid(), -6);
        $token = 'ptok_'.$suffix.'_'.Str::random(40);

        $customer = User::create([
            'name' => 'Portal Reg Cust '.$suffix,
            'email' => "portal-reg-{$suffix}@example.com",
            'password' => Hash::make('password'),
            'role' => 'customer',
            'status' => 'active',
            'phone' => '604558'.substr($suffix, -4),
            'is_test_data' => $isTestData,
        ]);

        $leadAttrs = [
            'contact_name' => $customer->name,
            'email' => $customer->email,
            'phone' => $customer->phone,
            'address' => '300 Portal Reg Ave',
            'service_category' => 'drywall_paint',
            'status' => 'converted',
            'source' => 'website',
            'customer_id' => $customer->id,
            'customer_portal_token' => $token,
            'is_test_data' => $isTestData,
        ];

        $lead = $isTestData
            ? Lead::withTestData()->create($leadAttrs)
            : Lead::create($leadAttrs);

        $jobAttrs = [
            'lead_id' => $lead->id,
            'customer_id' => $customer->id,
            'address' => '300 Portal Reg Ave',
            'service_category' => 'drywall_paint',
            'status' => 'payment_pending',
            'scope_of_work' => 'Portal token regression',
            'review_request_sent_at' => now(),
            'is_test_data' => $isTestData,
        ];

        $job = $isTestData
            ? Job::withTestData()->create($jobAttrs)
            : Job::create($jobAttrs);

        $invoiceAttrs = [
            'job_id' => $job->id,
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-PREG-'.$suffix,
            'status' => 'awaiting_payment',
            'amount' => 1050,
            'balance' => 1050,
            'is_test_data' => $isTestData,
        ];

        if ($isTestData) {
            Invoice::withTestData()->create($invoiceAttrs);
        } else {
            Invoice::create($invoiceAttrs);
        }

        return compact('token', 'lead', 'job');
    }

    public function test_review_portal_token_returns_200_for_production_lead(): void
    {
        $ctx = $this->makePortalJobContext(false);

        $this->getJson('/api/portal/'.$ctx['token'].'/review')
            ->assertOk()
            ->assertJsonPath('job.id', $ctx['job']->id)
            ->assertJsonPath('can_submit', true);
    }

    public function test_review_portal_token_returns_200_when_lead_is_test_flagged(): void
    {
        $ctx = $this->makePortalJobContext(true);

        $this->assertFalse(
            Lead::where('customer_portal_token', $ctx['token'])->exists(),
            'scoped query must hide flagged lead (root cause of portal 404)'
        );

        $this->getJson('/api/portal/'.$ctx['token'].'/review')
            ->assertOk()
            ->assertJsonPath('job.id', $ctx['job']->id);
    }

    public function test_stripe_checkout_portal_token_returns_200_for_production_lead(): void
    {
        $ctx = $this->makePortalJobContext(false);

        $this->postJson('/api/portal/'.$ctx['token'].'/stripe/checkout')
            ->assertOk()
            ->assertJsonStructure(['provider', 'checkout_url', 'session_id']);
    }

    public function test_stripe_checkout_portal_token_returns_200_when_lead_is_test_flagged(): void
    {
        $ctx = $this->makePortalJobContext(true);

        $this->assertFalse(
            Lead::where('customer_portal_token', $ctx['token'])->exists()
        );

        $this->postJson('/api/portal/'.$ctx['token'].'/stripe/checkout')
            ->assertOk()
            ->assertJsonStructure(['provider', 'checkout_url', 'session_id']);
    }
}
