<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Job;
use App\Models\Lead;
use App\Models\Quote;
use App\Models\User;
use App\Services\Customers\CustomerMergeService;
use App\Services\Customers\CustomerValidateService;
use App\Services\EmailService;
use App\Services\SmsService;
use Database\Seeders\Milestone4Seeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CustomerDataQualityA33Test extends TestCase
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
        if (! Schema::hasColumn('customers', 'data_quality_flags')) {
            $this->artisan('migrate', [
                '--path' => 'database/migrations/2026_07_27_000003_customer_data_quality_a33.php',
                '--force' => true,
            ]);
        }
        $this->seed(Milestone4Seeder::class);
    }

    private function makeCustomerUser(string $suffix, array $profile = []): Customer
    {
        $user = User::withTestData()->create([
            'name' => $profile['name'] ?? 'Test Customer '.$suffix,
            'email' => $profile['email'] ?? "cust-a33-{$suffix}@example.com",
            'password' => Hash::make('password'),
            'role' => 'customer',
            'status' => 'active',
            'phone' => $profile['phone'] ?? '+1604555'.substr($suffix, -4),
            'is_test_data' => (bool) ($profile['is_test_data'] ?? false),
        ]);

        return Customer::withTestData()->create([
            'user_id' => $user->id,
            'name' => $profile['name'] ?? $user->name,
            'phone' => $profile['phone'] ?? $user->phone,
            'email' => $profile['email'] ?? $user->email,
            'address' => $profile['address'] ?? null,
            'is_test_data' => (bool) ($profile['is_test_data'] ?? false),
        ]);
    }

    public function test_1_validate_customers_dry_run_reports_counts(): void
    {
        $suffix = substr(uniqid(), -6);
        $this->makeCustomerUser($suffix, ['name' => 'Unknown caller', 'phone' => 'bad']);
        $this->makeCustomerUser('t'.$suffix, ['name' => 'Real Person', 'phone' => '+16045551234', 'is_test_data' => true]);

        $result = app(CustomerValidateService::class)->run(apply: false);

        $this->assertTrue($result['dry_run']);
        $this->assertGreaterThan(0, $result['scanned']);
        $this->assertGreaterThan(0, $result['flagged_quality']);
        $this->assertArrayHasKey('invalid_name', $result['flags_by_reason']);
        $this->assertGreaterThanOrEqual(1, $result['skipped_test_data']);
    }

    public function test_2_duplicate_phone_grouping(): void
    {
        $suffix = substr(uniqid(), -6);
        $a = $this->makeCustomerUser('a'.$suffix, ['phone' => '604-555-0199', 'name' => 'Dup A '.$suffix]);
        $b = $this->makeCustomerUser('b'.$suffix, ['phone' => '6045550199', 'name' => 'Dup B '.$suffix]);

        app(CustomerValidateService::class)->run(apply: true);

        $a->refresh();
        $b->refresh();
        $this->assertNotNull($a->duplicate_group_id);
        $this->assertSame($a->duplicate_group_id, $b->duplicate_group_id);
    }

    public function test_3_merge_moves_jobs_and_quotes_to_primary(): void
    {
        $suffix = substr(uniqid(), -6);
        $owner = User::where('role', 'owner')->first();
        $a = $this->makeCustomerUser('ma'.$suffix, ['phone' => '+1604555'.substr($suffix, 0, 4), 'name' => 'Merge A']);
        $b = $this->makeCustomerUser('mb'.$suffix, ['phone' => '+1604556'.substr($suffix, 0, 4), 'name' => 'Merge B']);
        $this->assertNotSame($a->user_id, $b->user_id);

        Job::create([
            'customer_id' => $a->user_id,
            'job_title' => 'Job A',
            'address' => 'Addr',
            'service_category' => 'drywall_paint',
            'scope_of_work' => 'Merge test job A',
            'status' => 'in_progress',
            'is_test_data' => true,
        ]);
        Job::create([
            'customer_id' => $a->user_id,
            'job_title' => 'Job A2',
            'address' => 'Addr',
            'service_category' => 'drywall_paint',
            'scope_of_work' => 'Merge test job A2',
            'status' => 'in_progress',
            'is_test_data' => true,
        ]);
        $this->assertSame(2, Job::withTestData()->where('customer_id', $a->user_id)->count());
        Quote::create([
            'customer_id' => $b->user_id,
            'quote_number' => 'Q-A33-'.$suffix,
            'status' => 'draft',
            'scope_of_work' => 'Test quote',
            'contractor_base_price' => 100,
            'customer_price_before_gst' => 125,
            'gst_rate' => 5,
            'gst' => 6.25,
            'customer_total' => 131.25,
            'is_test_data' => true,
        ]);

        $result = app(CustomerMergeService::class)->merge(
            [$a->id, $b->id],
            $a->id,
            $owner,
            ['name' => $a->id, 'phone' => $a->id],
        );

        $this->assertSame(0, $result['counts']['jobs']);
        $this->assertSame(1, $result['counts']['quotes']);
        $this->assertSame(2, Job::withTestData()->where('customer_id', $a->user_id)->count());
        $this->assertSame(1, Quote::withTestData()->where('customer_id', $a->user_id)->count());
        $b->refresh();
        $this->assertSame($a->id, $b->merged_into_customer_id);
    }

    public function test_4_merge_failure_rolls_back(): void
    {
        $suffix = substr(uniqid(), -6);
        $owner = User::where('role', 'owner')->first();
        $a = $this->makeCustomerUser('ra'.$suffix, ['phone' => '+1604556'.substr($suffix, 0, 4)]);
        $b = $this->makeCustomerUser('rb'.$suffix, ['phone' => '+1604556'.substr($suffix, 0, 4)]);
        Job::create([
            'customer_id' => $b->user_id,
            'job_title' => 'Rollback job',
            'address' => 'X',
            'status' => 'in_progress',
            'is_test_data' => true,
        ]);

        try {
            app(CustomerMergeService::class)->merge(
                [$a->id, $b->id],
                $a->id,
                $owner,
                [],
                simulateFailure: true,
            );
            $this->fail('Expected merge to throw');
        } catch (\Throwable) {
            // expected
        }

        $b->refresh();
        $this->assertNull($b->merged_into_customer_id);
        $this->assertSame(1, Job::withTestData()->where('customer_id', $b->user_id)->count());
    }

    public function test_5_do_not_contact_blocks_sms_and_email(): void
    {
        $suffix = substr(uniqid(), -6);
        $c = $this->makeCustomerUser('dnc'.$suffix, [
            'phone' => '+1604557'.substr($suffix, 0, 4),
            'email' => "dnc-{$suffix}@example.com",
        ]);
        $c->update(['do_not_contact' => true, 'communication_preference' => 'both']);

        $sms = app(SmsService::class)->send($c->phone, 'hi', 'unit_test', $c->user_id, null);
        $this->assertFalse($sms['success']);
        $this->assertSame('do_not_contact', $sms['reason']);

        $email = app(EmailService::class)->send(
            $c->email,
            'Subject',
            'emails.notification',
            ['heading' => 'Hi', 'body' => 'Test'],
            'unit_test',
            $c->user_id,
            null,
        );
        $this->assertFalse($email['success']);
        $this->assertSame('do_not_contact', $email['reason']);
    }

    public function test_6_invalid_name_flags(): void
    {
        $suffix = substr(uniqid(), -6);
        $c = $this->makeCustomerUser('inv'.$suffix, ['name' => 'Unknown caller', 'phone' => '+1604558'.substr($suffix, 0, 4)]);
        $c->refresh();
        $this->assertContains('invalid_name', $c->data_quality_flags ?? []);

        $c2 = $this->makeCustomerUser('at'.$suffix, ['name' => 'bad@email.com', 'phone' => '+1604559'.substr($suffix, 0, 4)]);
        $c2->refresh();
        $this->assertContains('email_in_name', $c2->data_quality_flags ?? []);
    }

    public function test_7_test_data_customers_excluded_from_validate_needs_review_counts(): void
    {
        $suffix = substr(uniqid(), -6);
        $this->makeCustomerUser('test'.$suffix, ['name' => 'Unknown caller', 'is_test_data' => true]);
        $result = app(CustomerValidateService::class)->run(apply: true);
        $this->assertGreaterThanOrEqual(1, $result['skipped_test_data']);
        $bad = Customer::withTestData()->where('is_test_data', true)->where('name', 'Unknown caller')->first();
        $this->assertNull($bad->data_quality_flags);
    }

    public function test_8_export_includes_profile_and_links(): void
    {
        $suffix = substr(uniqid(), -6);
        $owner = User::where('role', 'owner')->first();
        $c = $this->makeCustomerUser('ex'.$suffix);
        Job::create([
            'customer_id' => $c->user_id,
            'job_title' => 'Export job',
            'address' => 'A',
            'status' => 'completed',
            'is_test_data' => true,
        ]);

        $response = $this->actingAs($owner)->getJson("/api/customers/{$c->id}/export");
        $response->assertOk();
        $response->assertJsonPath('profile.id', $c->id);
        $this->assertNotEmpty($response->json('jobs'));
    }

    public function test_9_delete_blocked_with_active_job(): void
    {
        $suffix = substr(uniqid(), -6);
        $owner = User::where('role', 'owner')->first();
        $c = $this->makeCustomerUser('del'.$suffix);
        Job::create([
            'customer_id' => $c->user_id,
            'job_title' => 'Active block',
            'address' => 'A',
            'status' => 'in_progress',
            'is_test_data' => true,
        ]);

        $response = $this->actingAs($owner)->deleteJson("/api/customers/{$c->id}", [
            'confirmation' => 'delete',
        ]);
        $response->assertStatus(422);
        $this->assertStringContainsString('active job', strtolower($response->json('message')));
        $this->assertTrue(Customer::withTestData()->whereKey($c->id)->exists());
    }
}
