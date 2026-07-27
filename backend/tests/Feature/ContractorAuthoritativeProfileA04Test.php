<?php

namespace Tests\Feature;

use App\Models\Contractor;
use App\Models\Job;
use App\Models\Payout;
use App\Models\User;
use App\Services\Contractors\ContractorDirectoryService;
use App\Services\Contractors\ContractorProfileCompleteness;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Audit A-04 / PM-06 — authoritative contractor profile.
 */
class ContractorAuthoritativeProfileA04Test extends TestCase
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
        if (! Schema::hasColumn('contractors', 'state')) {
            $this->artisan('migrate', [
                '--path' => 'database/migrations/2026_07_27_000004_contractor_authoritative_profile_a04.php',
                '--force' => true,
            ]);
        }
    }

    private function makeOwner(): User
    {
        return User::create([
            'name' => 'Owner',
            'email' => 'owner-a04-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
            'role' => 'owner',
            'status' => 'active',
        ]);
    }

    private function makePm(): User
    {
        return User::create([
            'name' => 'PM A04',
            'email' => 'pm-a04-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
            'role' => 'pm',
            'status' => 'active',
        ]);
    }

    private function makeOrphanContractorUser(string $name = 'Francis'): User
    {
        return User::create([
            'name' => $name,
            'email' => strtolower(str_replace(' ', '', $name)).'-a04-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
            'role' => 'contractor',
            'status' => 'active',
            'phone' => '6045550199',
        ]);
    }

    private function makeApprovedProfile(User $user, array $extra = []): Contractor
    {
        return Contractor::create(array_merge([
            'user_id' => $user->id,
            'legal_name' => $user->name,
            'operating_name' => $user->name,
            'contact_name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone ?? '6045550100',
            'services' => ['drywall'],
            'cities' => ['Vancouver'],
            'wcb_status' => 'approved',
            'liability_insurance_status' => 'approved',
            'approval_status' => 'approved',
            'state' => 'approved',
        ], $extra));
    }

    private function makeCustomer(): User
    {
        return User::create([
            'name' => 'Customer A04',
            'email' => 'cust-a04-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
            'role' => 'customer',
            'status' => 'active',
        ]);
    }

    public function test_1_root_cause_orphan_user_missing_from_directory_before_sync(): void
    {
        $francis = $this->makeOrphanContractorUser('Francis');
        $customer = $this->makeCustomer();
        Job::create([
            'customer_id' => $customer->id,
            'contractor_id' => $francis->id,
            'status' => 'in_progress',
            'address' => '1 Test St',
        ]);

        $this->assertSame(0, Contractor::where('user_id', $francis->id)->count());
        $this->assertTrue(Job::where('contractor_id', $francis->id)->exists());

        Sanctum::actingAs($this->makeOwner());
        $before = $this->getJson('/api/contractors');
        $before->assertOk();
        $ids = collect($before->json('data'))->pluck('user_id');
        $this->assertFalse($ids->contains($francis->id), 'Before sync Francis must NOT appear in directory');
    }

    public function test_2_after_sync_assigned_contractors_appear_in_admin_directory(): void
    {
        $francis = $this->makeOrphanContractorUser('Francis');
        $customer = $this->makeCustomer();
        Job::create([
            'customer_id' => $customer->id,
            'contractor_id' => $francis->id,
            'status' => 'in_progress',
            'address' => '1 Test St',
        ]);

        $sync = app(ContractorDirectoryService::class)->syncProfilesAndLinks(true);
        $this->assertGreaterThanOrEqual(1, $sync['profiles_created']);

        $profile = Contractor::where('user_id', $francis->id)->first();
        $this->assertNotNull($profile);

        Sanctum::actingAs($this->makeOwner());
        $res = $this->getJson('/api/contractors');
        $res->assertOk();
        $ids = collect($res->json('data'))->pluck('user_id');
        $this->assertTrue($ids->contains($francis->id), 'Francis must appear after profile sync');
        $this->assertFalse(
            app(ContractorDirectoryService::class)->usersAssignedWithoutProfile()->contains($francis->id)
        );
    }

    public function test_3_pm_sees_contractor_assigned_to_their_jobs(): void
    {
        $pm = $this->makePm();
        $francis = $this->makeOrphanContractorUser('Francis');
        app(ContractorDirectoryService::class)->syncProfilesAndLinks(true);
        $customer = $this->makeCustomer();

        Job::create([
            'customer_id' => $customer->id,
            'contractor_id' => $francis->id,
            'contractor_profile_id' => Contractor::where('user_id', $francis->id)->value('id'),
            'pm_id' => $pm->id,
            'status' => 'in_progress',
            'address' => '2 Test St',
        ]);

        Sanctum::actingAs($pm);
        $res = $this->getJson('/api/contractors');
        $res->assertOk();
        $ids = collect($res->json('data'))->pluck('user_id');
        $this->assertTrue($ids->contains($francis->id), 'PM must see Francis on their job');
    }

    public function test_4_dashboard_count_matches_directory_count(): void
    {
        $owner = $this->makeOwner();
        $a = $this->makeOrphanContractorUser('Alpha');
        $b = $this->makeOrphanContractorUser('Beta');
        app(ContractorDirectoryService::class)->syncProfilesAndLinks(true);

        $beta = Contractor::where('user_id', $b->id)->first();
        $beta->forceFill(['state' => 'deactivated', 'approval_status' => 'suspended'])->save();

        $directoryCount = app(ContractorDirectoryService::class)->directoryCount();

        Sanctum::actingAs($owner);
        $dash = $this->getJson('/api/dashboard/admin/kpis');
        $dash->assertOk();
        $this->assertSame($directoryCount, (int) $dash->json('total_contractors'));

        $list = $this->getJson('/api/contractors');
        $this->assertSame($directoryCount, (int) $list->json('total'));
        $this->assertTrue(collect($list->json('data'))->pluck('user_id')->contains($a->id));
        $this->assertFalse(collect($list->json('data'))->pluck('user_id')->contains($b->id));
    }

    public function test_5_suspended_contractor_blocked_from_new_assignment(): void
    {
        $owner = $this->makeOwner();
        $user = $this->makeOrphanContractorUser('Suspended Con');
        $profile = $this->makeApprovedProfile($user);
        $profile->forceFill(['state' => 'suspended', 'approval_status' => 'suspended'])->save();

        $customer = $this->makeCustomer();
        $job = Job::create([
            'customer_id' => $customer->id,
            'status' => 'new_job',
            'address' => '3 Test St',
        ]);

        Sanctum::actingAs($owner);
        $res = $this->postJson("/api/jobs/{$job->id}/assign-contractor", [
            'contractor_id' => $user->id,
        ]);
        $res->assertStatus(422);
        $this->assertStringContainsString(
            'suspended',
            strtolower(json_encode($res->json('errors') ?? $res->json()))
        );
    }

    public function test_6_incomplete_profile_shows_specific_missing_steps(): void
    {
        $user = $this->makeOrphanContractorUser('Incomplete');
        $profile = Contractor::create([
            'user_id' => $user->id,
            'legal_name' => 'Incomplete Co',
            'operating_name' => 'Incomplete Co',
            'contact_name' => 'Incomplete',
            'email' => $user->email,
            'phone' => '6045550111',
            'services' => ['drywall'],
            'cities' => ['Surrey'],
            'wcb_status' => 'not_uploaded',
            'liability_insurance_status' => 'approved',
            'approval_status' => 'pending',
            'state' => 'profile_incomplete',
        ]);

        $steps = app(ContractorProfileCompleteness::class)->missingSteps($profile->fresh());
        $keys = array_column($steps, 'key');
        $this->assertContains('wcb', $keys);
        $this->assertNotContains('liability_insurance', $keys);

        Sanctum::actingAs($this->makeOwner());
        $res = $this->getJson("/api/contractors/{$profile->id}");
        $res->assertOk();
        $payloadSteps = collect($res->json('missing_steps'))->pluck('key');
        $this->assertTrue($payloadSteps->contains('wcb'));
        $this->assertStringContainsString(
            'WCB',
            collect($res->json('missing_steps'))->firstWhere('key', 'wcb')['label'] ?? ''
        );
    }

    public function test_7_backfill_reports_confident_links_vs_manual_review(): void
    {
        $good = $this->makeOrphanContractorUser('Good Link');
        $customer = $this->makeCustomer();
        $job = Job::create([
            'customer_id' => $customer->id,
            'contractor_id' => $good->id,
            'status' => 'in_progress',
            'address' => '4 Test St',
        ]);
        Payout::create([
            'job_id' => $job->id,
            'contractor_id' => $good->id,
            'payout_type' => 'contractor',
            'split_type' => 'contractor',
            'payout_amount' => 100,
            'status' => 'pending',
        ]);

        $wrongRole = User::create([
            'name' => 'Not A Contractor',
            'email' => 'not-con-a04-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
            'role' => 'customer',
            'status' => 'active',
        ]);
        $orphanJob = Job::create([
            'customer_id' => $customer->id,
            'contractor_id' => $wrongRole->id,
            'status' => 'in_progress',
            'address' => '5 Test St',
        ]);

        $result = app(ContractorDirectoryService::class)->syncProfilesAndLinks(true);

        $this->assertGreaterThanOrEqual(1, $result['profiles_created']);
        $this->assertGreaterThanOrEqual(1, $result['jobs_linked']);
        $this->assertGreaterThanOrEqual(1, $result['payouts_linked']);
        $this->assertNotEmpty($result['manual_review']);
        $this->assertTrue(
            collect($result['manual_review'])->contains(
                fn ($r) => $r['type'] === 'job' && (int) $r['id'] === $orphanJob->id
            )
        );
        $this->assertNotNull($job->fresh()->contractor_profile_id);
    }

    public function test_8_pm_cannot_assign_suspended_and_stripe_hidden_from_pm(): void
    {
        $pm = $this->makePm();
        $user = $this->makeOrphanContractorUser('Stripe Con');
        $profile = $this->makeApprovedProfile($user);
        $user->update([
            'stripe_account_id' => 'acct_test_secret',
            'stripe_payout_ready' => true,
        ]);

        $customer = $this->makeCustomer();
        Job::create([
            'customer_id' => $customer->id,
            'pm_id' => $pm->id,
            'contractor_id' => $user->id,
            'contractor_profile_id' => $profile->id,
            'status' => 'in_progress',
            'address' => '6 Test St',
        ]);

        Sanctum::actingAs($pm);
        $list = $this->getJson('/api/contractors');
        $list->assertOk();
        $row = collect($list->json('data'))->firstWhere('id', $profile->id);
        $this->assertNotNull($row);
        $this->assertArrayNotHasKey('stripe', $row);

        $profile->forceFill(['state' => 'suspended', 'approval_status' => 'suspended'])->save();
        $newJob = Job::create([
            'customer_id' => $customer->id,
            'pm_id' => $pm->id,
            'status' => 'new_job',
            'address' => '7 Test St',
        ]);
        $blocked = $this->postJson("/api/jobs/{$newJob->id}/assign-contractor", [
            'contractor_id' => $user->id,
        ]);
        $blocked->assertStatus(422);
    }
}
