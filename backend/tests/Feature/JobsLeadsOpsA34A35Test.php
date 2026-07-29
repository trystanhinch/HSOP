<?php

namespace Tests\Feature;

use App\Models\Job;
use App\Models\Lead;
use App\Models\NextAction;
use App\Models\Quote;
use App\Models\User;
use App\Services\Jobs\JobAttentionService;
use App\Services\Leads\LeadDuplicateService;
use App\Services\Workflow\JobLifecycleService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A-34 Jobs ops filters + A-35 Leads confidence/duplicates.
 */
class JobsLeadsOpsA34A35Test extends TestCase
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
        if (Schema::hasTable('leads') && ! Schema::hasColumn('leads', 'duplicate_group_id')) {
            $this->artisan('migrate', [
                '--path' => 'database/migrations/2026_07_29_000010_a34_a35_jobs_leads_ops.php',
                '--force' => true,
            ]);
        }
    }

    private function owner(): User
    {
        return User::create([
            'name' => 'A34 Owner',
            'email' => 'a34-owner-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
            'role' => 'owner',
            'status' => 'active',
        ]);
    }

    private function customer(array $attrs = []): User
    {
        return User::create(array_merge([
            'name' => 'A34 Cust',
            'email' => 'a34-cust-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
            'role' => 'customer',
            'status' => 'active',
            'phone' => '+1604555'.random_int(1000, 9999),
        ], $attrs));
    }

    public function test_1_jobs_list_supports_attention_and_labeled_filter_params(): void
    {
        $owner = $this->owner();
        $customer = $this->customer();
        $job = Job::create([
            'customer_id' => $customer->id,
            'status' => 'quote_approved',
            'address' => '34 Attention Ave',
            'job_title' => 'Attention Job',
            'is_test_data' => false,
        ]);

        Sanctum::actingAs($owner);
        $res = $this->getJson('/api/jobs?attention=1')->assertOk()->json();
        $ids = collect($res['data'] ?? [])->pluck('id')->all();
        $this->assertContains($job->id, $ids);

        $row = collect($res['data'] ?? [])->firstWhere('id', $job->id);
        $this->assertTrue((bool) $row['attention']);
        $this->assertContains('needs_schedule', $row['attention_reasons']);
        $this->assertArrayHasKey('next_action', $row);
        $this->assertArrayHasKey('last_update_at', $row);
    }

    public function test_2_jobs_search_by_phone_finds_job(): void
    {
        $owner = $this->owner();
        $phone = '+16045559876';
        $customer = $this->customer(['phone' => $phone, 'name' => 'Phone Search Cust']);
        $job = Job::create([
            'customer_id' => $customer->id,
            'status' => 'scheduled',
            'address' => '98 Phone St',
            'job_title' => 'Phone Search Job',
            'is_test_data' => false,
        ]);

        Sanctum::actingAs($owner);
        $res = $this->getJson('/api/jobs/search?q=6045559876')->assertOk()->json();
        $ids = collect($res['data'] ?? [])->pluck('id')->all();
        $this->assertContains($job->id, $ids);
    }

    public function test_3_job_overdue_matches_dashboard_exception_missing_updates(): void
    {
        $owner = $this->owner();
        $customer = $this->customer();
        $job = Job::create([
            'customer_id' => $customer->id,
            'status' => 'in_progress',
            'address' => '7 Overdue Rd',
            'is_test_data' => false,
            'created_at' => now()->subDays(20),
            'updated_at' => now()->subDays(20),
        ]);

        $attention = app(JobAttentionService::class);
        $enriched = $attention->enrich($job->fresh());
        $this->assertTrue($enriched['attention']);
        $this->assertContains('missing_updates', $enriched['attention_reasons']);
        $this->assertTrue($enriched['overdue']);

        // Same definition as PM dashboard: in_progress + no recent updates
        $this->assertTrue(
            in_array($job->status, JobLifecycleService::IN_PROGRESS_STATUSES, true)
        );

        Sanctum::actingAs($owner);
        $res = $this->getJson('/api/jobs?attention=1&status=in_progress')->assertOk()->json();
        $this->assertContains($job->id, collect($res['data'] ?? [])->pluck('id')->all());
    }

    public function test_4_leads_list_shows_confidence_and_review_reason(): void
    {
        $owner = $this->owner();
        $lead = Lead::create([
            'contact_name' => 'Review Lead',
            'phone' => 'not-a-phone',
            'email' => 'bad',
            'status' => 'new',
            'needs_manual_review' => true,
            'review_reason' => 'Low confidence phone/email',
            'parse_metadata' => [
                'field_confidence' => [
                    ['field' => 'phone', 'score' => 20, 'valid' => false, 'source_text' => 'not-a-phone'],
                    ['field' => 'email', 'score' => 10, 'valid' => false, 'source_text' => 'bad'],
                ],
            ],
            'is_test_data' => false,
        ]);

        Sanctum::actingAs($owner);
        $res = $this->getJson('/api/leads?needs_review=true&view=needs_review')->assertOk()->json();
        $row = collect($res['data'] ?? [])->firstWhere('id', $lead->id);
        $this->assertNotNull($row);
        $this->assertSame('Low confidence phone/email', $row['review_reason']);
        $this->assertNotNull($row['confidence_summary']['min_score']);
        $this->assertLessThan(70, $row['confidence_summary']['min_score']);
    }

    public function test_5_convert_blocked_without_valid_contact_unless_owner_override(): void
    {
        $owner = $this->owner();
        $lead = Lead::create([
            'contact_name' => 'Unknown caller',
            'phone' => 'abc',
            'email' => null,
            'status' => 'new',
            'needs_manual_review' => true,
            'is_test_data' => false,
        ]);

        Sanctum::actingAs($owner);
        $this->postJson('/api/leads/'.$lead->id.'/convert-to-job')
            ->assertStatus(422);

        $this->postJson('/api/leads/'.$lead->id.'/convert-to-job', [
            'owner_override' => true,
            'owner_override_reason' => 'Verified verbally with customer',
        ])->assertCreated();

        $this->assertSame('converted', $lead->fresh()->status);
        $this->assertNotNull($lead->fresh()->convert_override_at);
        $this->assertSame('Verified verbally with customer', $lead->fresh()->convert_override_reason);
    }

    public function test_6_duplicate_group_shows_recommended_primary(): void
    {
        $owner = $this->owner();
        $phone = '+16045551234';
        $a = Lead::create([
            'contact_name' => 'Dup A',
            'phone' => $phone,
            'email' => 'a@example.com',
            'status' => 'new',
            'needs_manual_review' => false,
            'is_test_data' => false,
        ]);
        $b = Lead::create([
            'contact_name' => 'Dup B',
            'phone' => $phone,
            'email' => null,
            'status' => 'new',
            'needs_manual_review' => true,
            'is_test_data' => false,
        ]);

        Sanctum::actingAs($owner);
        $this->postJson('/api/leads/regroup-duplicates')->assertOk();

        $a->refresh();
        $b->refresh();
        $this->assertNotNull($a->duplicate_group_id);
        $this->assertSame($a->duplicate_group_id, $b->duplicate_group_id);

        $group = $this->getJson('/api/leads/duplicate-groups/'.$a->duplicate_group_id)->assertOk()->json();
        $this->assertSame($a->id, $group['recommended_primary_id']);
        $this->assertTrue(collect($group['leads'])->contains(fn ($l) => $l['id'] === $a->id && $l['is_recommended_primary']));
    }

    public function test_quote_number_search_finds_job(): void
    {
        $owner = $this->owner();
        $customer = $this->customer();
        $job = Job::create([
            'customer_id' => $customer->id,
            'status' => 'quote_sent',
            'address' => 'Quote Search Ln',
            'is_test_data' => false,
        ]);
        Quote::create([
            'job_id' => $job->id,
            'customer_id' => $customer->id,
            'quote_number' => 'QT-A34SEARCH',
            'status' => 'sent',
            'customer_total' => 100,
            'subtotal' => 95,
            'is_test_data' => false,
        ]);

        Sanctum::actingAs($owner);
        $res = $this->getJson('/api/jobs/search?q=QT-A34SEARCH')->assertOk()->json();
        $this->assertContains($job->id, collect($res['data'] ?? [])->pluck('id')->all());
    }
}
