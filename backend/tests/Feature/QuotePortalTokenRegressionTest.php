<?php

namespace Tests\Feature;

use App\Mail\HsopNotificationMail;
use App\Models\Lead;
use App\Models\Quote;
use App\Models\Setting;
use App\Models\User;
use App\Services\JobNotificationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * P0 regression: quote-ready emails must use /quote/view/{customer_token},
 * and /api/portal/{customer_portal_token} must resolve via token (including
 * leads hidden by ExcludeTestDataScope).
 */
class QuotePortalTokenRegressionTest extends TestCase
{
    use DatabaseTransactions;

    public function createApplication()
    {
        $app = parent::createApplication();
        $app['config']->set('database.default', 'mysql');
        $app['config']->set('database.connections.mysql.database', 'hsop_job_command');
        $app['config']->set('app.frontend_url', 'https://app.serviceop.ca');

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        Setting::set('email_globally_enabled', 'true');
        Setting::set('sms_globally_enabled', 'false');
    }

    public function test_quote_sent_email_uses_quote_view_url_not_portal(): void
    {
        Mail::fake();

        $suffix = substr(uniqid(), -6);
        $portalToken = 'portal_'.$suffix.'_'.Str::random(48);
        $quoteToken = 'quote_'.$suffix.'_'.Str::random(48);

        $customer = User::create([
            'name' => 'Quote Cust '.$suffix,
            'email' => "quote-cust-{$suffix}@example.com",
            'password' => Hash::make('password'),
            'role' => 'customer',
            'status' => 'active',
            'phone' => '604555'.substr($suffix, -4),
            'is_test_data' => false,
        ]);

        $lead = Lead::create([
            'contact_name' => $customer->name,
            'email' => $customer->email,
            'phone' => $customer->phone,
            'address' => '221 3rd St E Unit 406',
            'service_category' => 'drywall_paint',
            'status' => 'quote_needed',
            'source' => 'website',
            'customer_id' => $customer->id,
            'customer_portal_token' => $portalToken,
            'is_test_data' => false,
        ]);

        $quote = Quote::create([
            'lead_id' => $lead->id,
            'job_id' => null,
            'customer_id' => $customer->id,
            'quote_number' => 'Q-REG-'.$suffix,
            'status' => 'sent',
            'customer_token' => $quoteToken,
            'customer_total' => 2231.25,
            'gst' => 106.25,
            'customer_price_before_gst' => 2125.00,
            'subtotal' => 2125.00,
            'sent_at' => now(),
            'is_test_data' => false,
        ]);

        // Callers historically passed a portal URL — quoteSent must ignore that.
        $wrongPortalUrl = 'https://app.serviceop.ca/portal/'.$portalToken;
        app(JobNotificationService::class)->quoteSent($quote->fresh(['customer', 'lead']), $wrongPortalUrl);

        Mail::assertSent(HsopNotificationMail::class, function (HsopNotificationMail $mail) use ($quoteToken, $portalToken) {
            $url = $mail->data['actionUrl'] ?? '';
            $this->assertStringContainsString('/quote/view/'.$quoteToken, $url);
            $this->assertStringNotContainsString('/portal/', $url);
            $this->assertStringNotContainsString($portalToken, $url);
            $this->assertSame('Your Quote Is Ready', $mail->mailSubject);

            return true;
        });

        $this->getJson('/api/quote/view/'.$quoteToken)
            ->assertOk()
            ->assertJsonPath('customer_total', '2231.25');
    }

    public function test_portal_token_returns_200_for_production_lead(): void
    {
        $suffix = substr(uniqid(), -6);
        $token = 'ptok_'.$suffix.'_'.Str::random(48);

        Lead::create([
            'contact_name' => 'Portal Customer '.$suffix,
            'email' => "portal-cust-{$suffix}@example.com",
            'phone' => '604556'.substr($suffix, -4),
            'address' => '100 Portal Ave',
            'service_category' => 'drywall_paint',
            'status' => 'quote_needed',
            'source' => 'website',
            'customer_portal_token' => $token,
            'is_test_data' => false,
        ]);

        $this->getJson('/api/portal/'.$token)
            ->assertOk()
            ->assertJsonPath('lead.contact_name', 'Portal Customer '.$suffix)
            ->assertJsonPath('token', $token);
    }

    public function test_portal_token_returns_200_even_when_lead_is_test_flagged(): void
    {
        $suffix = substr(uniqid(), -6);
        $token = 'ptest_'.$suffix.'_'.Str::random(48);

        // Reproduce the production 404: ExcludeTestDataScope hides the lead from
        // Lead::where(...) unless withTestData() is used on token lookup.
        $this->assertFalse(
            Lead::where('customer_portal_token', $token)->exists(),
            'precondition: token should not exist yet'
        );

        Lead::withTestData()->create([
            'contact_name' => 'Flagged Portal '.$suffix,
            'email' => "flagged-portal-{$suffix}@example.com",
            'phone' => '604557'.substr($suffix, -4),
            'address' => '200 Flagged Ave',
            'service_category' => 'drywall_paint',
            'status' => 'quote_needed',
            'source' => 'website',
            'customer_portal_token' => $token,
            'is_test_data' => true,
        ]);

        $this->assertFalse(
            Lead::where('customer_portal_token', $token)->exists(),
            'scoped query must hide flagged lead (root cause of portal 404)'
        );
        $this->assertTrue(
            Lead::withTestData()->where('customer_portal_token', $token)->exists()
        );

        $this->getJson('/api/portal/'.$token)
            ->assertOk()
            ->assertJsonPath('lead.contact_name', 'Flagged Portal '.$suffix);
    }
}
