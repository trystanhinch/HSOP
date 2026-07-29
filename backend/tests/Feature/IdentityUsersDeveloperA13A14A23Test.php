<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureDeveloper;
use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\Company;
use App\Models\PmBrandAssignment;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\Milestone4Seeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A-13 / A-14 / A-23 — Legal identity, users & roles, developer diagnostics.
 */
class IdentityUsersDeveloperA13A14A23Test extends TestCase
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

        if (! Schema::hasColumn('companies', 'legal_name')) {
            $this->artisan('migrate', [
                '--path' => 'database/migrations/2026_07_29_000005_a13_a14_a23_identity_users_developer.php',
                '--force' => true,
            ]);
        }
    }

    private function owner(array $attrs = []): User
    {
        $user = User::where('role', 'owner')->first()
            ?: User::factory()->create(['role' => 'owner', 'name' => 'Owner', 'status' => 'active']);

        $user->forceFill(array_merge([
            'password' => 'password',
            'status' => 'active',
            'is_developer' => false,
        ], $attrs))->save();

        return $user->fresh();
    }

    private function company(): Company
    {
        return Company::withTestData()->orderBy('id')->first()
            ?: Company::create([
                'name' => 'Test Co',
                'slug' => 'test-co-'.uniqid(),
                'service_type' => 'drywall',
                'is_active' => true,
                'is_test_data' => true,
            ]);
    }

    /** TC-1: Update legal_name/remittance_address — audit with old/new + actor. */
    public function test_1_sensitive_identity_update_is_audited(): void
    {
        $owner = $this->owner();
        $company = $this->company();
        $company->update([
            'legal_name' => 'Old Legal Ltd',
            'remittance_address' => '1 Old St',
            'gst_verification_status' => 'verified',
        ]);

        Sanctum::actingAs($owner);

        $res = $this->postJson('/api/settings', [
            'legal_name' => 'New Legal Ltd',
            'remittance_address' => '99 Remit Ave',
            'confirm_sensitive_change' => true,
            'current_password' => 'password',
        ]);

        $res->assertOk();
        $company->refresh();
        $this->assertSame('New Legal Ltd', $company->legal_name);
        $this->assertSame('99 Remit Ave', $company->remittance_address);

        $log = AuditLog::query()
            ->where('action_type', 'company_identity_updated')
            ->where('object_id', $company->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame($owner->id, $log->user_id);
        $this->assertSame('Old Legal Ltd', $log->previous_value['legal_name'] ?? null);
        $this->assertSame('New Legal Ltd', $log->new_value['legal_name'] ?? null);
        $this->assertNotEmpty($log->new_value['effective_at'] ?? null);
    }

    /** TC-2: Same update without re-confirmation is blocked. */
    public function test_2_sensitive_update_requires_confirmation(): void
    {
        $owner = $this->owner();
        $company = $this->company();
        $company->update(['legal_name' => 'Keep Legal']);

        Sanctum::actingAs($owner);

        $res = $this->postJson('/api/settings', [
            'legal_name' => 'Blocked Legal',
        ]);

        $res->assertStatus(422);
        $this->assertArrayHasKey('confirm_sensitive_change', $res->json('errors') ?? []);
        $this->assertSame('Keep Legal', $company->fresh()->legal_name);
    }

    /** TC-3: Users list shows role, brand scope, status, last active. */
    public function test_3_users_list_exposes_scope_and_lifecycle_fields(): void
    {
        $owner = $this->owner();
        $pm = User::create([
            'name' => 'Scope PM',
            'email' => 'scope-pm-'.uniqid().'@test.local',
            'password' => 'password',
            'role' => 'pm',
            'status' => 'active',
            'last_login_at' => now()->subHour(),
            'invitation_status' => 'accepted',
            'is_test_data' => false,
        ]);

        $brand = Brand::create([
            'domain' => 'scope-'.uniqid().'.ca',
            'slug' => 'scope-'.uniqid(),
            'company_name' => 'Scope Brand Co',
            'status' => 'active',
        ]);

        if (Schema::hasTable('pm_brand_assignments')) {
            PmBrandAssignment::create([
                'user_id' => $pm->id,
                'brand_id' => $brand->id,
                'assigned_by' => $owner->id,
                'assigned_at' => now(),
            ]);
        }

        Sanctum::actingAs($owner);
        $res = $this->getJson('/api/admin/users');
        $res->assertOk();

        $row = collect($res->json())->firstWhere('id', $pm->id);
        $this->assertNotNull($row);
        $this->assertSame('pm', $row['role']);
        $this->assertSame('active', $row['status']);
        $this->assertSame('accepted', $row['invitation_status']);
        $this->assertSame('not_yet_implemented', $row['two_factor_status']);
        $this->assertNotEmpty($row['last_active_at'] ?? $row['last_login_at'] ?? null);
        $names = collect($row['brand_scope'] ?? [])->pluck('company_name')->all();
        $this->assertContains('Scope Brand Co', $names);
    }

    /** TC-4: Suspend revokes Sanctum tokens immediately. */
    public function test_4_suspend_invalidates_existing_token(): void
    {
        $owner = $this->owner();
        $pm = User::create([
            'name' => 'Suspend Me',
            'email' => 'suspend-'.uniqid().'@test.local',
            'password' => 'password',
            'role' => 'pm',
            'status' => 'active',
            'is_test_data' => false,
        ]);

        $token = $pm->createToken('auth_token')->plainTextToken;
        $this->assertSame(1, $pm->tokens()->count());

        // Confirm token works before suspension.
        $this->withToken($token)->getJson('/api/me')->assertOk();

        Sanctum::actingAs($owner);
        $this->postJson("/api/admin/users/{$pm->id}/suspend")->assertOk();

        $this->assertSame(0, $pm->fresh()->tokens()->count());
        $this->assertSame('inactive', $pm->fresh()->status);

        // Drop actingAs so the next request authenticates only via the (deleted) bearer token.
        $this->app['auth']->forgetGuards();

        $this->withToken($token)->getJson('/api/me')->assertStatus(401);

        // Even a freshly minted token is rejected by EnsureActiveUser.
        $zombie = $pm->createToken('zombie')->plainTextToken;
        $this->app['auth']->forgetGuards();
        $this->withToken($zombie)
            ->getJson('/api/me')
            ->assertStatus(403)
            ->assertJsonPath('code', 'account_inactive');
        $this->assertSame(0, $pm->fresh()->tokens()->count());
    }

    /** TC-5: Owner without developer permission cannot access DB overview. */
    public function test_5_database_overview_blocked_without_developer(): void
    {
        $owner = $this->owner(['is_developer' => false]);
        Sanctum::actingAs($owner);

        $this->getJson('/api/admin/database-overview')
            ->assertStatus(403)
            ->assertJsonPath('code', 'developer_required');
    }

    /** TC-6: Developer requires reauth; samples off by default. */
    public function test_6_developer_requires_reauth_and_hides_samples_by_default(): void
    {
        $owner = $this->owner(['is_developer' => true]);
        Cache::forget(EnsureDeveloper::UNLOCK_CACHE_PREFIX.$owner->id);
        Sanctum::actingAs($owner);

        $this->getJson('/api/admin/database-overview')
            ->assertStatus(403)
            ->assertJsonPath('code', 'developer_reauth_required');

        $this->postJson('/api/admin/developer/unlock', ['password' => 'password'])
            ->assertOk();

        $res = $this->getJson('/api/admin/database-overview');
        $res->assertOk();
        $this->assertSame('health', $res->json('mode'));
        $this->assertFalse($res->json('include_samples'));

        foreach ($res->json('tables') as $table) {
            $this->assertArrayNotHasKey('sample', $table);
        }

        $withSamples = $this->getJson('/api/admin/database-overview?include_samples=1');
        $withSamples->assertOk();
        $this->assertTrue($withSamples->json('include_samples'));

        $accessLog = AuditLog::query()
            ->where('action_type', 'database_overview_accessed')
            ->where('user_id', $owner->id)
            ->latest('id')
            ->first();
        $this->assertNotNull($accessLog);
    }
}
