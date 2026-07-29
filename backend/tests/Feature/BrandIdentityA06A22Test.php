<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Job;
use App\Models\Lead;
use App\Models\Quote;
use App\Models\Setting;
use App\Models\User;
use App\Services\BrandResolver;
use App\Services\Accounting\InvoiceService;
use Database\Seeders\Milestone4Seeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * A-06 / A-22 — Brand Identity Tests
 *
 * Validates:
 * 1. Brand resolver returns correct operating brand name from Brand Content
 * 2. Brand name snapshot is stored on quote creation
 * 3. Editing the brand name doesn't change historical snapshots
 * 4. Legacy Branding settings tab rejects company_name edits
 * 5. Brand preview endpoint returns all required channels
 * 6. Customer portal response includes brand_name
 * 7. Fallback when no brand is assigned
 * 8. Invoice creation snapshots the brand name
 */
class BrandIdentityA06A22Test extends TestCase
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
        Setting::set('sms_globally_enabled', 'false');
        Setting::set('email_globally_enabled', 'false');
    }

    private function makeOwner(): User
    {
        return User::where('role', 'owner')->first()
            ?: User::factory()->create(['role' => 'owner', 'name' => 'Owner']);
    }

    private function makeBrandWithLead(string $brandName = 'Acutera Drywall and Paint'): array
    {
        $brand = Brand::create([
            'domain' => 'acutera-'.uniqid().'.ca',
            'slug' => 'acutera-'.uniqid(),
            'company_name' => $brandName,
            'status' => 'active',
        ]);

        $lead = Lead::create([
            'contact_name' => 'Brand Test Customer',
            'email' => 'brand-test-'.uniqid().'@test.local',
            'phone' => '6045551234',
            'address' => '123 Brand St',
            'service_category' => 'drywall',
            'status' => 'new',
            'brand_id' => $brand->id,
            'is_test_data' => false,
        ]);

        return compact('brand', 'lead');
    }

    /**
     * TC-1: BrandResolver returns the operating brand name (Acutera), not HSOP or ServiceOP.
     */
    public function test_1_brand_resolver_returns_operating_brand_name(): void
    {
        ['brand' => $brand, 'lead' => $lead] = $this->makeBrandWithLead('Acutera Drywall and Paint');

        $resolver = app(BrandResolver::class);
        $name = $resolver->forLead($lead);

        $this->assertEquals('Acutera Drywall and Paint', $name);
        $this->assertNotEquals('ServiceOP', $name);
        $this->assertNotEquals('HSOP Drywall & Paint', $name);
    }

    /**
     * TC-2: Brand name snapshot is stored on quote creation via the quote store endpoint.
     */
    public function test_2_quote_creation_snapshots_brand_name(): void
    {
        $owner = $this->makeOwner();
        ['brand' => $brand, 'lead' => $lead] = $this->makeBrandWithLead('Acutera Drywall and Paint');

        $customer = User::create([
            'name' => 'Quote Customer',
            'email' => 'qc-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
            'role' => 'customer',
            'status' => 'active',
            'phone' => '6045550401',
        ]);
        $pm = User::create([
            'name' => 'Quote PM',
            'email' => 'qpm-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
            'role' => 'pm',
            'status' => 'active',
            'phone' => '6045550402',
        ]);
        $company = Company::first() ?: Company::factory()->create();
        $job = Job::create([
            'lead_id' => $lead->id,
            'company_id' => $company->id,
            'customer_id' => $customer->id,
            'pm_id' => $pm->id,
            'job_title' => 'Brand Test Job',
            'address' => '123 Brand St',
            'contractor_submitted_price' => 500,
            'status' => 'contractor_assigned',
            'is_test_data' => false,
        ]);

        $this->actingAs($owner)
            ->postJson('/api/quotes', [
                'job_id' => $job->id,
                'scope_of_work' => 'Drywall repair',
                'contractor_price' => 500,
            ])
            ->assertStatus(201);

        $quote = Quote::where('job_id', $job->id)->latest()->first();
        $this->assertNotNull($quote);
        $this->assertEquals('Acutera Drywall and Paint', $quote->brand_name_snapshot);
    }

    /**
     * TC-3: Editing the brand name in Brand Content does NOT change a previously-snapshotted quote.
     */
    public function test_3_brand_name_edit_does_not_change_historical_snapshot(): void
    {
        $owner = $this->makeOwner();
        ['brand' => $brand, 'lead' => $lead] = $this->makeBrandWithLead('Acutera Drywall and Paint');

        $customer = User::create([
            'name' => 'Snapshot Customer',
            'email' => 'sc-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
            'role' => 'customer',
            'status' => 'active',
            'phone' => '6045550403',
        ]);
        $pm = User::create([
            'name' => 'Snapshot PM',
            'email' => 'spm-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
            'role' => 'pm',
            'status' => 'active',
            'phone' => '6045550404',
        ]);
        $company = Company::first() ?: Company::factory()->create();
        $job = Job::create([
            'lead_id' => $lead->id,
            'company_id' => $company->id,
            'customer_id' => $customer->id,
            'pm_id' => $pm->id,
            'job_title' => 'Snapshot Test Job',
            'address' => '123 Snapshot St',
            'contractor_submitted_price' => 500,
            'status' => 'contractor_assigned',
            'is_test_data' => false,
        ]);

        $this->actingAs($owner)
            ->postJson('/api/quotes', [
                'job_id' => $job->id,
                'scope_of_work' => 'Ceiling repair',
                'contractor_price' => 500,
            ])
            ->assertStatus(201);

        $quote = Quote::where('job_id', $job->id)->latest()->first();
        $originalSnapshot = $quote->brand_name_snapshot;
        $this->assertEquals('Acutera Drywall and Paint', $originalSnapshot);

        // Edit the brand name in Brand Content
        $brand->update(['company_name' => 'Acutera Renovations Inc']);

        // Reload the quote — snapshot must not have changed
        $quote->refresh();
        $this->assertEquals($originalSnapshot, $quote->brand_name_snapshot);
        $this->assertNotEquals('Acutera Renovations Inc', $quote->brand_name_snapshot);
    }

    /**
     * TC-4: Legacy Branding settings tab rejects company_name edits (read-only).
     */
    public function test_4_legacy_branding_settings_tab_rejects_company_name_edit(): void
    {
        $owner = $this->makeOwner();

        $response = $this->actingAs($owner)
            ->postJson('/api/settings', [
                'company_name' => 'New Brand Name',
            ]);

        $response->assertStatus(422);
        $response->assertJsonFragment([
            'message' => 'Company name is managed via Brand Content (Settings → Brand Content). The legacy Branding tab is read-only.',
        ]);
    }

    /**
     * TC-5: Brand preview endpoint returns all required channels for owner.
     */
    public function test_5_brand_preview_endpoint_returns_all_channels(): void
    {
        $owner = $this->makeOwner();
        ['brand' => $brand] = $this->makeBrandWithLead('Acutera Drywall and Paint');

        $response = $this->actingAs($owner)
            ->getJson('/api/brand-preview');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'platform_name',
            'platform_note',
            'brands' => [
                '*' => [
                    'id',
                    'slug',
                    'domain',
                    'operating_name',
                    'invoice_name',
                    'sender_name',
                    'portal_brand',
                    'payment_descriptor',
                    'review_destination',
                    'platform_note',
                ],
            ],
        ]);

        $response->assertJsonPath('platform_name', BrandResolver::PLATFORM_NAME);

        $brands = $response->json('brands');
        $acutera = collect($brands)->firstWhere('id', $brand->id);
        $this->assertNotNull($acutera);
        $this->assertEquals('Acutera Drywall and Paint', $acutera['operating_name']);
        $this->assertEquals('Acutera Drywall and Paint', $acutera['invoice_name']);
        $this->assertEquals('Acutera Drywall and Paint', $acutera['sender_name']);
        $this->assertEquals('Acutera Drywall and Paint', $acutera['portal_brand']);
        $this->assertLessThanOrEqual(22, mb_strlen($acutera['payment_descriptor']));
        $this->assertStringContainsString('Acutera', $acutera['review_destination']);
    }

    /**
     * TC-5b: Brand preview is unauthorized for customers.
     */
    public function test_5b_brand_preview_unauthorized_for_customer(): void
    {
        $customer = User::create([
            'name' => 'Preview Customer',
            'email' => 'preview-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
            'role' => 'customer',
            'status' => 'active',
            'phone' => '6045550405',
        ]);

        $this->actingAs($customer)
            ->getJson('/api/brand-preview')
            ->assertStatus(403);
    }

    /**
     * TC-6: Customer portal response includes brand_name field from BrandResolver.
     */
    public function test_6_customer_portal_returns_brand_name(): void
    {
        ['lead' => $lead] = $this->makeBrandWithLead('Acutera Drywall and Paint');
        $token = 'brand-portal-'.uniqid();
        $lead->update(['customer_portal_token' => $token]);

        $response = $this->getJson("/api/portal/{$token}");

        $response->assertStatus(200);
        $response->assertJsonPath('brand_name', 'Acutera Drywall and Paint');
    }

    /**
     * TC-7: BrandResolver::fallback used when lead has no brand.
     */
    public function test_7_brand_resolver_fallback_when_no_brand(): void
    {
        $lead = Lead::create([
            'contact_name' => 'No Brand Customer',
            'email' => 'nobrand-'.uniqid().'@test.local',
            'phone' => '6045551235',
            'address' => '456 No Brand St',
            'service_category' => 'drywall',
            'status' => 'new',
            'brand_id' => null,
            'is_test_data' => true,
        ]);

        $resolver = app(BrandResolver::class);
        $name = $resolver->forLead($lead);

        $this->assertNotEmpty($name);
        $this->assertEquals($resolver->fallback(), $name);
    }

    /**
     * TC-8: Invoice created via InvoiceService snapshots the brand name.
     */
    public function test_8_invoice_creation_snapshots_brand_name(): void
    {
        ['brand' => $brand, 'lead' => $lead] = $this->makeBrandWithLead('Acutera Drywall and Paint');

        $customer = User::create([
            'name' => 'Invoice Customer',
            'email' => 'ic-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
            'role' => 'customer',
            'status' => 'active',
            'phone' => '6045550406',
        ]);
        $pm = User::create([
            'name' => 'Invoice PM',
            'email' => 'ipm-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
            'role' => 'pm',
            'status' => 'active',
            'phone' => '6045550407',
        ]);
        $company = Company::first() ?: Company::factory()->create();
        $job = Job::create([
            'lead_id' => $lead->id,
            'company_id' => $company->id,
            'customer_id' => $customer->id,
            'pm_id' => $pm->id,
            'job_title' => 'Invoice Test Job',
            'address' => '123 Invoice St',
            'contractor_submitted_price' => 800,
            'status' => 'contractor_assigned',
            'is_test_data' => false,
        ]);

        $invoice = app(InvoiceService::class)->createFromJob($job);

        $this->assertEquals('Acutera Drywall and Paint', $invoice->brand_name_snapshot);
    }
}
