<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\AvailabilityWindow;
use App\Models\Booking;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Job;
use App\Models\Lead;
use App\Models\PmBrandAssignment;
use App\Models\Quote;
use App\Models\User;
use App\Services\Authorization\PmAuthorizationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Audit PM-01 / PM-02 — brand assignment + own-work + availability + customers.
 * Decisions: 1A pivot, 2A own-work-only, 3A brand-level+own windows, 4A customers via own work.
 */
class PmAuthorizationPm01Pm02Test extends TestCase
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
        if (! Schema::hasTable('pm_brand_assignments')) {
            $this->artisan('migrate', [
                '--path' => 'database/migrations/2026_07_28_000001_pm_brand_assignments_pm01.php',
                '--force' => true,
            ]);
        }
    }

    private function owner(): User
    {
        return User::create([
            'name' => 'PM01 Owner',
            'email' => 'pm01-owner-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
            'role' => 'owner',
            'status' => 'active',
        ]);
    }

    private function pm(string $tag = 'a'): User
    {
        return User::create([
            'name' => "PM01 {$tag}",
            'email' => "pm01-{$tag}-".uniqid().'@test.local',
            'password' => bcrypt('password'),
            'role' => 'pm',
            'status' => 'active',
        ]);
    }

    private function customerUser(): User
    {
        return User::create([
            'name' => 'PM01 Cust',
            'email' => 'pm01-cust-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
            'role' => 'customer',
            'status' => 'active',
            'phone' => '6045550100',
        ]);
    }

    private function brand(string $slug): Brand
    {
        return Brand::create([
            'domain' => $slug.'.pm01.test',
            'slug' => $slug.'-'.uniqid(),
            'company_name' => 'PM01 '.$slug,
            'status' => 'active',
            'service_categories' => [],
        ]);
    }

    private function assignBrand(User $pm, Brand $brand, ?User $by = null): void
    {
        PmBrandAssignment::create([
            'user_id' => $pm->id,
            'brand_id' => $brand->id,
            'assigned_by' => $by?->id,
            'assigned_at' => now(),
        ]);
    }

    private function makeLead(User $pm, Brand $brand, ?User $customer = null): Lead
    {
        return Lead::create([
            'contact_name' => 'Lead '.$pm->name,
            'phone' => '6045550200',
            'email' => 'lead-'.uniqid().'@test.local',
            'address' => '1 Test St',
            'service_category' => 'drywall_paint',
            'status' => 'new',
            'assigned_pm_id' => $pm->id,
            'brand_id' => $brand->id,
            'customer_id' => $customer?->id,
        ]);
    }

    private function makeJob(User $pm, ?User $customer = null, ?Lead $lead = null): Job
    {
        return Job::create([
            'lead_id' => $lead?->id,
            'customer_id' => $customer?->id ?? $this->customerUser()->id,
            'pm_id' => $pm->id,
            'address' => '99 Job St',
            'service_category' => 'drywall_paint',
            'status' => 'new_job',
            'job_title' => 'PM01 Job',
        ]);
    }

    public function test_1_empty_brand_assignment_blocks_availability_brands(): void
    {
        $pm = $this->pm('empty');
        $this->brand('orphan');

        Sanctum::actingAs($pm);
        $this->getJson('/api/availability/brands')->assertOk()->assertExactJson([]);
        $this->getJson('/api/me/pm-brands')->assertOk()->assertJsonPath('brand_ids', []);
    }

    public function test_2_pm_cannot_see_other_pms_lead_or_job(): void
    {
        $pmA = $this->pm('a');
        $pmB = $this->pm('b');
        $brand = $this->brand('shared');
        $this->assignBrand($pmA, $brand);
        $this->assignBrand($pmB, $brand);

        $leadB = $this->makeLead($pmB, $brand);
        $jobB = $this->makeJob($pmB, null, $leadB);

        Sanctum::actingAs($pmA);
        $this->getJson('/api/leads/'.$leadB->id)->assertForbidden();
        $this->getJson('/api/jobs/'.$jobB->id)->assertForbidden();
        $this->assertTrue(
            AuditLog::query()->where('action_type', 'pm_unauthorized_access_blocked')
                ->where('user_id', $pmA->id)->exists()
        );
    }

    public function test_3_shared_brand_own_work_only_on_lists(): void
    {
        $pmA = $this->pm('lista');
        $pmB = $this->pm('listb');
        $brand = $this->brand('listshare');
        $this->assignBrand($pmA, $brand);
        $this->assignBrand($pmB, $brand);

        $leadA = $this->makeLead($pmA, $brand);
        $leadB = $this->makeLead($pmB, $brand);
        $jobA = $this->makeJob($pmA, null, $leadA);
        $jobB = $this->makeJob($pmB, null, $leadB);

        Sanctum::actingAs($pmA);
        $leads = $this->getJson('/api/leads')->assertOk()->json('data') ?? $this->getJson('/api/leads')->json();
        $leadIds = collect(is_array($leads) && isset($leads[0]) ? $leads : ($leads['data'] ?? []))->pluck('id');
        $this->assertTrue($leadIds->contains($leadA->id));
        $this->assertFalse($leadIds->contains($leadB->id));

        $jobs = collect($this->getJson('/api/jobs')->json('data') ?? $this->getJson('/api/jobs')->json());
        // jobs index may return paginator or plain list
        if ($jobs->has('data')) {
            $jobs = collect($jobs->get('data'));
        }
        $jobIds = $jobs->pluck('id');
        $this->assertTrue($jobIds->contains($jobA->id));
        $this->assertFalse($jobIds->contains($jobB->id));
    }

    public function test_4_quote_and_invoice_idor_blocked(): void
    {
        $pmA = $this->pm('qa');
        $pmB = $this->pm('qb');
        $brand = $this->brand('qid');
        $this->assignBrand($pmA, $brand);
        $this->assignBrand($pmB, $brand);
        $customer = $this->customerUser();
        $jobB = $this->makeJob($pmB, $customer);

        $quote = Quote::createWithUniqueQuoteNumber([
            'job_id' => $jobB->id,
            'customer_id' => $customer->id,
            'status' => 'draft',
            'scope_of_work' => 'test',
            'subtotal' => 100,
            'customer_price_before_gst' => 100,
            'gst' => 5,
            'customer_total' => 105,
        ]);
        $invoice = Invoice::create([
            'job_id' => $jobB->id,
            'customer_id' => $customer->id,
            'status' => 'draft',
            'subtotal' => 100,
            'gst' => 5,
            'amount' => 105,
            'balance' => 105,
            'invoice_number' => 'INV-PM01-'.uniqid(),
        ]);

        Sanctum::actingAs($pmA);
        $this->getJson('/api/quotes/'.$quote->id)->assertForbidden();
        $this->putJson('/api/quotes/'.$quote->id, ['scope_of_work' => 'hacked'])->assertForbidden();
        $this->getJson('/api/invoices/'.$invoice->id)->assertForbidden();
        $this->putJson('/api/invoices/'.$invoice->id, ['notes' => 'hacked'])->assertForbidden();

        $invoiceList = collect($this->getJson('/api/invoices')->json('data') ?? []);
        $this->assertFalse($invoiceList->pluck('id')->contains($invoice->id));
    }

    public function test_5_customers_hidden_unless_tied_to_own_work(): void
    {
        $pm = $this->pm('cust');
        $brand = $this->brand('custb');
        $this->assignBrand($pm, $brand);

        $mine = $this->customerUser();
        $theirs = $this->customerUser();
        Customer::create(['user_id' => $mine->id, 'name' => $mine->name, 'email' => $mine->email, 'phone' => '6045551111']);
        $otherCustomer = Customer::create(['user_id' => $theirs->id, 'name' => $theirs->name, 'email' => $theirs->email, 'phone' => '6045552222']);

        $this->makeJob($pm, $mine);

        Sanctum::actingAs($pm);
        $list = collect($this->getJson('/api/customers')->json('data') ?? []);
        $ids = $list->pluck('user_id');
        $this->assertTrue($ids->contains($mine->id));
        $this->assertFalse($ids->contains($theirs->id));

        $this->getJson('/api/customers/'.$otherCustomer->id)->assertForbidden();
    }

    public function test_6_availability_unassigned_brand_rejected(): void
    {
        $pm = $this->pm('avail');
        $allowed = $this->brand('allowed');
        $denied = $this->brand('denied');
        $this->assignBrand($pm, $allowed);

        Sanctum::actingAs($pm);
        $brands = collect($this->getJson('/api/availability/brands')->json());
        $this->assertTrue($brands->pluck('id')->contains($allowed->id));
        $this->assertFalse($brands->pluck('id')->contains($denied->id));

        $this->getJson('/api/availability/windows?brand_id='.$denied->id)->assertForbidden();
        $this->postJson('/api/availability/windows', [
            'brand_id' => $denied->id,
            'day_of_week' => 1,
            'start_time' => '09:00',
            'end_time' => '12:00',
        ])->assertForbidden();
    }

    public function test_7_pm_can_edit_brand_level_and_own_windows_not_other_pm(): void
    {
        $pmA = $this->pm('winA');
        $pmB = $this->pm('winB');
        $brand = $this->brand('wins');
        $this->assignBrand($pmA, $brand);
        $this->assignBrand($pmB, $brand);

        $brandLevel = AvailabilityWindow::create([
            'brand_id' => $brand->id,
            'pm_id' => null,
            'day_of_week' => 2,
            'start_time' => '09:00',
            'end_time' => '11:00',
            'slot_duration_minutes' => 60,
            'timezone' => 'America/Vancouver',
            'status' => 'active',
        ]);
        $otherPmWindow = AvailabilityWindow::create([
            'brand_id' => $brand->id,
            'pm_id' => $pmB->id,
            'day_of_week' => 3,
            'start_time' => '13:00',
            'end_time' => '15:00',
            'slot_duration_minutes' => 60,
            'timezone' => 'America/Vancouver',
            'status' => 'active',
        ]);

        Sanctum::actingAs($pmA);
        $this->putJson('/api/availability/windows/'.$brandLevel->id, [
            'end_time' => '12:00',
        ])->assertOk();

        $this->putJson('/api/availability/windows/'.$otherPmWindow->id, [
            'end_time' => '16:00',
        ])->assertForbidden();

        $windows = collect($this->getJson('/api/availability/windows')->json());
        $this->assertTrue($windows->pluck('id')->contains($brandLevel->id));
        $this->assertFalse($windows->pluck('id')->contains($otherPmWindow->id));
    }

    public function test_8_deactivation_blocked_with_active_booking(): void
    {
        $pm = $this->pm('deact');
        $brand = $this->brand('deactb');
        $this->assignBrand($pm, $brand);

        $window = AvailabilityWindow::create([
            'brand_id' => $brand->id,
            'pm_id' => null,
            'day_of_week' => 4,
            'start_time' => '10:00',
            'end_time' => '12:00',
            'slot_duration_minutes' => 60,
            'timezone' => 'America/Vancouver',
            'status' => 'active',
        ]);

        $lead = $this->makeLead($pm, $brand);
        Booking::create([
            'brand_id' => $brand->id,
            'lead_id' => $lead->id,
            'resource_key' => $window->resourceKey(),
            'slot_start' => now()->addDay(),
            'slot_end' => now()->addDay()->addHour(),
            'status' => 'confirmed',
            'timezone' => 'America/Vancouver',
        ]);

        Sanctum::actingAs($pm);
        $this->deleteJson('/api/availability/windows/'.$window->id)
            ->assertStatus(422)
            ->assertJsonFragment(['active_bookings' => 1]);
    }

    public function test_9_owner_sync_assignments_empty_clears_access(): void
    {
        $owner = $this->owner();
        $pm = $this->pm('sync');
        $brand = $this->brand('syncb');
        $this->assignBrand($pm, $brand, $owner);

        Sanctum::actingAs($owner);
        $this->putJson('/api/admin/pm-brand-assignments/'.$pm->id, [
            'brand_ids' => [],
        ])->assertOk();

        $this->assertSame(0, PmBrandAssignment::where('user_id', $pm->id)->count());
        $this->assertTrue(
            AuditLog::query()->where('action_type', 'pm_brand_assignments_changed')
                ->where('object_id', $pm->id)->exists()
        );

        Sanctum::actingAs($pm);
        $this->getJson('/api/availability/brands')->assertOk()->assertExactJson([]);
    }

    public function test_10_authz_service_customer_scope_matches_4a(): void
    {
        $pm = $this->pm('svc');
        $brand = $this->brand('svcb');
        $this->assignBrand($pm, $brand);
        $mine = $this->customerUser();
        $cust = Customer::create(['user_id' => $mine->id, 'name' => $mine->name, 'email' => $mine->email]);
        $this->makeLead($pm, $brand, $mine);

        $authz = app(PmAuthorizationService::class);
        $this->assertTrue($authz->customerIsInPmScope($pm, $cust));

        $other = Customer::create([
            'user_id' => $this->customerUser()->id,
            'name' => 'Other',
            'email' => 'other-'.uniqid().'@test.local',
        ]);
        $this->assertFalse($authz->customerIsInPmScope($pm, $other));
    }
}
