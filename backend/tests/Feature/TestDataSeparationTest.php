<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\User;
use App\Services\EmailService;
use App\Services\SmsService;
use App\Services\TestData\FlagTestDataService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TestDataSeparationTest extends TestCase
{
    use DatabaseTransactions;

    public function createApplication()
    {
        $app = parent::createApplication();
        $app['config']->set('database.default', 'mysql');
        $app['config']->set('database.connections.mysql.database', 'hsop_job_command');

        return $app;
    }

    public function test_global_scope_excludes_flagged_leads(): void
    {
        $suffix = substr(uniqid(), -6);
        $real = Lead::create([
            'contact_name' => 'Real Customer '.$suffix,
            'phone' => '604555'.substr($suffix, -4),
            'email' => "real-customer-{$suffix}@acutera.example.org",
            'status' => 'new',
            'source' => 'website',
            'project_description' => 'Real ceiling repair',
            'is_test_data' => false,
        ]);
        $test = Lead::withTestData()->create([
            'contact_name' => 'Walkthrough Tester '.$suffix,
            'phone' => '604556'.substr($suffix, -4),
            'email' => "walkthrough.tester.{$suffix}@example.com",
            'status' => 'new',
            'source' => 'website',
            'project_description' => 'QA walkthrough',
            'is_test_data' => true,
        ]);

        $this->assertTrue(Lead::whereKey($real->id)->exists());
        $this->assertFalse(Lead::whereKey($test->id)->exists());
        $this->assertTrue(Lead::withTestData()->whereKey($test->id)->exists());
    }

    public function test_sms_and_email_blocked_for_test_user(): void
    {
        $suffix = substr(uniqid(), -6);
        $user = User::withTestData()->create([
            'name' => 'Rotation Test '.$suffix,
            'email' => "rotation.test.blocked.{$suffix}@example.com",
            'password' => Hash::make('password'),
            'role' => 'customer',
            'status' => 'active',
            'phone' => '+1604555'.substr($suffix, -4),
            'is_test_data' => true,
        ]);

        $sms = app(SmsService::class)->send($user->phone, 'hello', 'unit_test', $user->id, null);
        $this->assertFalse($sms['success']);
        $this->assertSame('test_data', $sms['reason']);

        $email = app(EmailService::class)->send(
            $user->email,
            'Subject',
            'emails.notification',
            ['heading' => 'Hi', 'body' => 'Test'],
            'unit_test',
            $user->id,
            null
        );
        $this->assertFalse($email['success']);
        $this->assertSame('test_data', $email['reason']);
    }

    public function test_flag_command_dry_run_does_not_write(): void
    {
        $suffix = substr(uniqid(), -6);
        $lead = Lead::withTestData()->create([
            'contact_name' => 'DateFix Verify '.$suffix,
            'phone' => '604557'.substr($suffix, -4),
            'email' => "datefix-verify-{$suffix}@example.com",
            'status' => 'new',
            'source' => 'internal_test',
            'project_description' => 'DateFix verification job',
            'is_test_data' => false,
        ]);

        $this->artisan('serviceop:flag-test-data')
            ->assertSuccessful();

        $fresh = Lead::withTestData()->find($lead->id);
        $this->assertFalse((bool) $fresh->is_test_data);
    }

    public function test_flag_command_apply_flags_known_patterns(): void
    {
        $suffix = substr(uniqid(), -6);
        $walk = Lead::withTestData()->create([
            'contact_name' => 'Walkthrough Tester '.$suffix,
            'phone' => '604558'.substr($suffix, -4),
            'email' => "walkthrough.tester.{$suffix}@example.com",
            'status' => 'new',
            'source' => 'website',
            'project_description' => 'QA',
            'is_test_data' => false,
        ]);
        $legit = Lead::withTestData()->create([
            'contact_name' => 'Legitimate Homeowner '.$suffix,
            'phone' => '604559'.substr($suffix, -4),
            'email' => "homeowner-{$suffix}@acuteradrywall.ca",
            'status' => 'new',
            'source' => 'website',
            'project_description' => 'Water stain bedroom ceiling',
            'is_test_data' => false,
        ]);

        $this->artisan('serviceop:flag-test-data', ['--apply' => true])
            ->assertSuccessful();

        $this->assertTrue(Lead::withTestData()->find($walk->id)->is_test_data);
        $this->assertFalse(Lead::withTestData()->find($legit->id)->is_test_data);
        $this->assertFalse(Lead::whereKey($walk->id)->exists());
        $this->assertTrue(Lead::whereKey($legit->id)->exists());
    }

    public function test_flag_service_lists_example_com_without_apply(): void
    {
        $suffix = substr(uniqid(), -6);
        $email = "someone-{$suffix}@example.com";
        User::withTestData()->create([
            'name' => 'Example User '.$suffix,
            'email' => $email,
            'password' => Hash::make('password'),
            'role' => 'customer',
            'status' => 'active',
            'is_test_data' => false,
        ]);

        $result = app(FlagTestDataService::class)->run(apply: false);
        $this->assertTrue($result['dry_run']);
        $this->assertNotEmpty($result['flagged']['users'] ?? []);
        $this->assertSame(0, User::onlyTestData()->where('email', $email)->count());
    }

    public function test_owner_test_data_endpoints(): void
    {
        $owner = User::create([
            'name' => 'Owner TestData',
            'role' => 'owner',
            'email' => 'owner-testdata-'.uniqid().'@hsop.com',
            'password' => Hash::make('password'),
            'status' => 'active',
        ]);

        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/admin/test-data')
            ->assertOk()
            ->assertJsonStructure(['counts', 'app_env']);

        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/admin/test-data/dry-run')
            ->assertOk()
            ->assertJsonPath('dry_run', true);
    }
}
