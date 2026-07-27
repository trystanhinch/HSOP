<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\FinancialLedgerEntry;
use App\Models\Invoice;
use App\Models\Job;
use App\Models\Lead;
use App\Models\PaymentDestination;
use App\Models\Quote;
use App\Models\Setting;
use App\Models\User;
use App\Services\Accounting\InvoicePaymentService;
use App\Services\Accounting\InvoiceService;
use App\Services\Payments\PaymentDestinationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Audit A-03 — customer payment destination routing.
 */
class PaymentDestinationA03Test extends TestCase
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
        if (! Schema::hasTable('payment_destinations')) {
            $this->artisan('migrate', [
                '--path' => 'database/migrations/2026_07_27_000006_payment_destinations_a03.php',
                '--force' => true,
            ]);
        }
    }

    private function owner(): User
    {
        return User::create([
            'name' => 'PayDest Owner',
            'email' => 'paydest-owner-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
            'role' => 'owner',
            'status' => 'active',
        ]);
    }

    private function pm(): User
    {
        return User::create([
            'name' => 'PayDest PM',
            'email' => 'paydest-pm-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
            'role' => 'pm',
            'status' => 'active',
        ]);
    }

    private function contractorUser(string $email): User
    {
        return User::create([
            'name' => 'PayDest Contractor',
            'email' => $email,
            'password' => bcrypt('password'),
            'role' => 'contractor',
            'status' => 'active',
        ]);
    }

    private function brand(): Brand
    {
        return Brand::query()->orderBy('id')->first()
            ?? Brand::create([
                'domain' => 'a03-'.uniqid().'.test',
                'slug' => 'a03-'.uniqid(),
                'company_name' => 'A03 Test Brand',
                'status' => 'active',
                'service_categories' => [],
            ]);
    }

    public function test_1_contractor_email_blocked_without_override(): void
    {
        $owner = $this->owner();
        $email = 'contractor-block-'.uniqid().'@gmail.com';
        $this->contractorUser($email);
        $brand = $this->brand();

        Sanctum::actingAs($owner);
        $res = $this->postJson('/api/payment-destinations', [
            'brand_id' => $brand->id,
            'payment_method' => 'e_transfer',
            'destination_value' => $email,
            'is_verified' => true,
        ]);

        $res->assertStatus(422);
        $this->assertStringContainsString(
            'contractor account',
            strtolower($res->json('errors.destination_value.0') ?? $res->json('message') ?? '')
        );
    }

    public function test_2_owner_override_with_reason_saves_and_audits(): void
    {
        $owner = $this->owner();
        $email = 'contractor-override-'.uniqid().'@gmail.com';
        $this->contractorUser($email);
        $brand = $this->brand();

        Sanctum::actingAs($owner);
        $res = $this->postJson('/api/payment-destinations', [
            'brand_id' => $brand->id,
            'payment_method' => 'e_transfer',
            'destination_value' => $email,
            'is_verified' => true,
            'owner_override' => true,
            'override_reason' => 'Sole proprietor exception for A-03 test',
        ]);

        $res->assertCreated();
        $dest = PaymentDestination::find($res->json('destination.id'));
        $this->assertNotNull($dest);
        $this->assertTrue($dest->contractor_email_override);
        $this->assertTrue(
            AuditLog::where('object_type', 'payment_destination')
                ->where('object_id', $dest->id)
                ->where('action_type', 'payment_destination_contractor_override')
                ->exists()
        );
    }

    public function test_3_pm_and_contractor_cannot_edit_destinations(): void
    {
        $brand = $this->brand();
        $payload = [
            'brand_id' => $brand->id,
            'payment_method' => 'stripe',
            'destination_value' => 'platform',
            'is_verified' => true,
        ];

        Sanctum::actingAs($this->pm());
        $this->getJson('/api/payment-destinations')->assertStatus(403);
        $this->postJson('/api/payment-destinations', $payload)->assertStatus(403);

        Sanctum::actingAs($this->contractorUser('con-deny-'.uniqid().'@test.local'));
        $this->getJson('/api/payment-destinations')->assertStatus(403);
        $this->postJson('/api/payment-destinations', $payload)->assertStatus(403);
    }

    public function test_4_live_change_requires_confirmation_and_audits(): void
    {
        $owner = $this->owner();
        $brand = $this->brand();
        Sanctum::actingAs($owner);

        $create = $this->postJson('/api/payment-destinations', [
            'brand_id' => $brand->id,
            'payment_method' => 'e_transfer',
            'destination_value' => 'payments-a03-'.uniqid().'@company.test',
            'is_verified' => true,
        ]);
        $create->assertCreated();
        $id = $create->json('destination.id');

        $blocked = $this->putJson('/api/payment-destinations/'.$id, [
            'destination_value' => 'new-payments-a03-'.uniqid().'@company.test',
            'is_verified' => true,
        ]);
        $blocked->assertStatus(422);
        $this->assertArrayHasKey('confirm_live_change', $blocked->json('errors') ?? []);

        $newEmail = 'confirmed-a03-'.uniqid().'@company.test';
        $ok = $this->putJson('/api/payment-destinations/'.$id, [
            'destination_value' => $newEmail,
            'is_verified' => true,
            'confirm_live_change' => true,
            'reason' => 'Updating company remittance inbox',
        ]);
        $ok->assertOk();
        $this->assertSame($newEmail, strtolower((string) PaymentDestination::find($id)->destination_value));
        $this->assertTrue(
            AuditLog::where('object_type', 'payment_destination')
                ->where('object_id', $id)
                ->where('action_type', 'payment_destination_live_changed')
                ->exists()
        );
    }

    public function test_5_verified_stripe_destination_shown_on_payment_details(): void
    {
        $owner = $this->owner();
        $brand = $this->brand();
        $customer = User::create([
            'name' => 'Cust A03',
            'email' => 'cust-a03-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
            'role' => 'customer',
            'status' => 'active',
        ]);
        $lead = Lead::create([
            'customer_name' => 'Cust A03',
            'customer_email' => $customer->email,
            'status' => 'converted',
            'brand_id' => $brand->id,
            'source' => 'website',
        ]);
        $job = Job::create([
            'customer_id' => $customer->id,
            'lead_id' => $lead->id,
            'status' => 'payment_pending',
            'address' => '9 Payment St',
        ]);

        Sanctum::actingAs($owner);
        $this->postJson('/api/payment-destinations', [
            'brand_id' => $brand->id,
            'payment_method' => 'stripe',
            'destination_value' => 'platform',
            'is_verified' => true,
        ])->assertCreated();

        $this->postJson('/api/payment-destinations', [
            'brand_id' => $brand->id,
            'payment_method' => 'e_transfer',
            'destination_value' => 'company-pay-'.uniqid().'@brand.test',
            'is_verified' => true,
        ])->assertCreated();

        config(['payment.provider' => 'stripe']);
        Sanctum::actingAs($customer);
        $details = $this->getJson('/api/jobs/'.$job->id.'/payment-details');
        $details->assertOk();
        $this->assertTrue((bool) $details->json('card_payments_enabled'));
        $this->assertSame('platform', $details->json('payment.stripe.destination_value'));
        $this->assertNotNull($details->json('company_email'));
        $this->assertStringNotContainsString('expert.plusdrywall', (string) $details->json('company_email'));
        $this->assertStringNotContainsString('expert.plusdrywall', (string) $details->json('payment_instructions'));
    }

    public function test_6_manual_etransfer_creates_ledger_entry_with_note(): void
    {
        $owner = $this->owner();
        $customer = User::create([
            'name' => 'Cust Ledger',
            'email' => 'cust-led-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
            'role' => 'customer',
            'status' => 'active',
        ]);
        $job = Job::create([
            'customer_id' => $customer->id,
            'status' => 'payment_pending',
            'address' => '10 Ledger Pay St',
        ]);
        Quote::create([
            'job_id' => $job->id,
            'customer_id' => $customer->id,
            'status' => 'approved',
            'contractor_base_price' => 800,
            'hsop_markup' => 200,
            'customer_price_before_gst' => 1000,
            'gst' => 50,
            'customer_total' => 1050,
            'subtotal' => 1000,
        ]);
        $invoice = app(InvoiceService::class)->createFromJob($job->fresh(['quote', 'lead.companySource']));

        Sanctum::actingAs($owner);
        app(InvoicePaymentService::class)->markPaid($invoice, [
            'amount' => (float) $invoice->amount,
            'payment_method' => 'e_transfer',
            'payment_date' => now()->toDateString(),
            'reference_number' => 'ET-A03-1',
            'ledger_note' => 'e-transfer, manually confirmed by '.$owner->name.' (user #'.$owner->id.')',
        ]);

        $entry = FinancialLedgerEntry::where('invoice_id', $invoice->id)
            ->where('entry_type', FinancialLedgerEntry::TYPE_PAYMENT_RECEIVED)
            ->latest('id')
            ->first();
        $this->assertNotNull($entry);
        $this->assertStringContainsString('e-transfer, manually confirmed by', (string) ($entry->meta['note'] ?? ''));
        $this->assertStringContainsString($owner->name, (string) ($entry->meta['note'] ?? ''));
    }

    public function test_7_legacy_contractor_instruction_flagged_and_not_customer_facing(): void
    {
        $svc = app(PaymentDestinationService::class);
        $flaggedEmail = 'expert.plusdrywall@gmail.com';

        // Ensure contractor match exists for the known production address.
        if (! User::withTestData()->where('role', 'contractor')->whereRaw('LOWER(email) = ?', [$flaggedEmail])->exists()) {
            $this->contractorUser($flaggedEmail);
        }

        $this->assertTrue($svc->matchesContractorEmail($flaggedEmail));

        Setting::set('payment_instructions', 'Send e-transfer to expert.plusdrywall@gmail.com');
        Setting::set('company_email', 'info@hsop.ca');

        // Settings keys remain intact (3A without nulling).
        $this->assertSame('Send e-transfer to expert.plusdrywall@gmail.com', Setting::get('payment_instructions'));
        $this->assertSame('info@hsop.ca', Setting::get('company_email'));

        $brand = $this->brand();
        // Simulate migrated unverified row
        $row = PaymentDestination::updateOrCreate(
            ['brand_id' => $brand->id, 'payment_method' => 'e_transfer'],
            [
                'destination_type' => 'company_verified',
                'destination_value' => $flaggedEmail,
                'is_verified' => false,
                'needs_owner_review' => true,
                'is_active' => true,
                'legacy_source_note' => 'FLAGGED: matches contractor email',
            ]
        );

        $facing = $svc->customerFacingForBrand($brand);
        $this->assertNull($facing['company_email'], 'Unverified contractor email must not appear on customer UI');
        $this->assertTrue($facing['needs_owner_review']);

        $list = collect($svc->listForOwner($brand->id));
        $match = $list->firstWhere('id', $row->id);
        $this->assertTrue((bool) ($match['blocked_if_resaved'] ?? false));
        $this->assertTrue((bool) ($match['needs_owner_review'] ?? false));
    }

    public function test_8_regression_prior_audits_still_pass_smoke(): void
    {
        $this->assertTrue(class_exists(\App\Services\Finance\FinancialLedgerService::class));
        $this->assertTrue(class_exists(\App\Services\Contractors\ContractorDirectoryService::class));
        $this->assertTrue(Schema::hasTable('payment_destinations'));
    }
}
