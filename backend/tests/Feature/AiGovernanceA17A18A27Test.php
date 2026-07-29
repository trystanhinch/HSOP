<?php

namespace Tests\Feature;

use App\Models\AiActionLog;
use App\Models\AiConversationLog;
use App\Models\Brand;
use App\Models\Lead;
use App\Models\PmBrandAssignment;
use App\Models\Setting;
use App\Models\User;
use App\Services\Ai\AiActionGate;
use App\Services\Learning\AiConversationLogger;
use Database\Seeders\Milestone4Seeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A-17 / A-18 / A-27 — AI mode gate, log privacy/traceability, Command Center evidence.
 */
class AiGovernanceA17A18A27Test extends TestCase
{
    use DatabaseTransactions;

    public function createApplication()
    {
        $app = parent::createApplication();
        $app['config']->set('database.default', 'mysql');
        $app['config']->set('database.connections.mysql.database', 'hsop_job_command');
        $app['config']->set('payment.provider', 'mock');
        $app['config']->set('ai.provider', 'mock');
        $app['config']->set('services.sms.enabled', false);

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(Milestone4Seeder::class);
        Setting::set('ai_kill_switch', 'false');
        Setting::setBool('ai_simulation_mode', false);
        Setting::set('ai_mode_customer_messaging', 'autopilot');
        Setting::set('ai_mode_command_center', 'autopilot');

        if (! Schema::hasColumn('ai_action_logs', 'trace_id')) {
            $this->artisan('migrate', [
                '--path' => 'database/migrations/2026_07_29_000007_a17_a18_a27_ai_governance.php',
                '--force' => true,
            ]);
        }
    }

    private function owner(): User
    {
        return User::where('role', 'owner')->firstOrFail();
    }

    /** TC-1: High-risk customer message in Auto mode still requires approval. */
    public function test_1_high_risk_requires_approval_in_autopilot(): void
    {
        $owner = $this->owner();
        Sanctum::actingAs($owner);

        $res = $this->postJson('/api/ai/actions/run', [
            'action_key' => 'send_customer_message',
            'message' => 'Hello customer — please confirm.',
            'confirmed' => false,
        ]);

        $res->assertOk();
        $this->assertSame('approval_required', $res->json('status'));
        $this->assertTrue((bool) $res->json('requires_approval'));
        $this->assertSame('autopilot', $res->json('decision.mode'));
        $this->assertTrue((bool) $res->json('decision.action.hard_approval_floor'));

        $this->assertDatabaseHas('ai_action_logs', [
            'action_key' => 'send_customer_message',
            'outcome' => 'approval_required',
            'mode' => 'autopilot',
        ]);
    }

    /** TC-2: Retry with same idempotency key → one grouped incident. */
    public function test_2_retry_idempotent_and_grouped(): void
    {
        $owner = $this->owner();
        $gate = app(AiActionGate::class);
        $key = 'ai-test-idem-'.uniqid();

        $first = $gate->run('create_internal_note', $owner, [
            'idempotency_key' => $key,
            'confirmed' => true,
            'trigger_event' => 'ai_governance_test',
            'message' => 'note',
        ], fn () => ['wrote' => true]);

        $this->assertSame('executed', $first['status']);
        $root = $first['log'];

        $second = $gate->run('create_internal_note', $owner, [
            'idempotency_key' => $key,
            'confirmed' => true,
            'trigger_event' => 'ai_governance_test',
        ], fn () => ['wrote' => true, 'should_not' => true]);

        $third = $gate->run('create_internal_note', $owner, [
            'idempotency_key' => $key,
            'confirmed' => true,
            'trigger_event' => 'ai_governance_test',
        ], fn () => ['wrote' => true, 'should_not' => true]);

        $this->assertSame('deduplicated', $second['status']);
        $this->assertSame('deduplicated', $third['status']);
        $this->assertTrue((bool) $second['deduplicated']);

        $this->assertSame($root->trace_id, $second['log']->trace_id);
        $this->assertSame($root->id, $second['log']->parent_log_id);

        $children = AiActionLog::where('parent_log_id', $root->id)->count();
        $this->assertSame(2, $children);
        $this->assertGreaterThanOrEqual(2, (int) $root->fresh()->retry_count);

        // Activity feed shows roots only (one incident).
        Sanctum::actingAs($owner);
        $list = $this->getJson('/api/ai/action-logs?trigger_event=ai_governance_test');
        $list->assertOk();
        $roots = collect($list->json('data'))->where('idempotency_key', $key)->values();
        $this->assertCount(1, $roots);
        $this->assertGreaterThanOrEqual(2, (int) ($roots[0]['grouped_retries'] ?? $roots[0]['retry_count'] ?? 0));
    }

    private function makePm(string $name = 'Gov PM'): User
    {
        return User::create([
            'name' => $name,
            'email' => 'gov-pm-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
            'role' => 'pm',
            'status' => 'active',
        ]);
    }

    /** TC-3: Non-owner cannot reveal full conversation content. */
    public function test_3_non_owner_cannot_reveal_conversation(): void
    {
        $owner = $this->owner();
        $pm = $this->makePm('Reveal Blocked PM');

        $brand = Brand::where('domain', 'acuteradrywall.ca')->first()
            ?: Brand::create([
                'domain' => 'gov-reveal-'.uniqid().'.ca',
                'slug' => 'gov-reveal-'.uniqid(),
                'company_name' => 'Reveal Brand',
                'status' => 'active',
            ]);
        $session = \App\Models\IntakeSession::create([
            'brand_id' => $brand->id,
            'session_token' => \Illuminate\Support\Str::random(64),
            'conversation_state' => ['messages' => []],
            'expires_at' => now()->addDay(),
        ]);

        $log = AiConversationLog::create([
            'intake_session_id' => $session->id,
            'lead_id' => null,
            'turn_number' => 1,
            'role' => 'user',
            'content' => 'SECRET full conversation text about customer phone 604-555-0199',
            'content_preview' => 'SECRET full…',
            'created_at' => now(),
        ]);

        Sanctum::actingAs($owner);
        $ownerView = $this->getJson('/api/ai/conversation-logs?reveal_content=1&intake_session_id='.$session->id);
        $ownerView->assertOk();
        $row = collect($ownerView->json('data'))->firstWhere('id', $log->id);
        $this->assertNotNull($row);
        $this->assertStringContainsString('604-555-0199', (string) ($row['content'] ?? ''));

        Sanctum::actingAs($pm);
        $this->getJson('/api/ai/conversation-logs/'.$log->id.'/content')->assertForbidden();
        $this->getJson('/api/ai/conversation-logs?reveal_content=1')->assertForbidden();
    }

    /** TC-4: Command Center answers include citations to exact records. */
    public function test_4_command_center_cites_records(): void
    {
        $owner = $this->owner();
        $pm = $this->makePm('Cite PM');

        $lead = Lead::create([
            'contact_name' => 'Citation Lead',
            'email' => 'cite-'.uniqid().'@test.local',
            'phone' => '6045550333',
            'address' => '99 Cite St',
            'service_category' => 'drywall_paint',
            'status' => 'new',
            'assigned_pm_id' => $pm->id,
        ]);

        \App\Models\NextAction::create([
            'subject_type' => $lead->getMorphClass(),
            'subject_id' => $lead->id,
            'action_description' => 'Follow up citation lead',
            'responsible_role' => 'pm',
            'responsible_user_id' => $pm->id,
            'due_at' => now()->subHours(3),
            'status' => 'pending',
            'last_action_at' => now(),
        ]);

        Sanctum::actingAs($owner);
        $ask = $this->postJson('/api/command-center/ask', ['message' => 'Any leads stuck?']);
        $ask->assertOk();

        $citations = $ask->json('citations') ?: ($ask->json('assistant_message.meta.citations') ?? []);
        $this->assertNotEmpty($citations);
        $ids = collect($citations)->where('type', 'lead')->pluck('id')->all();
        $this->assertContains($lead->id, $ids);
        $this->assertSame('read-only', $ask->json('meta.response_kind'));
        $this->assertNotEmpty($ask->json('meta.data_refreshed_at'));
    }

    /** TC-5: PM blocked outside brand/own-work scope (PM-01/PM-02). */
    public function test_5_pm_blocked_outside_brand_scope(): void
    {
        $brandA = Brand::create([
            'domain' => 'gov-a-'.uniqid().'.ca',
            'slug' => 'gov-a-'.uniqid(),
            'company_name' => 'Gov Brand A',
            'status' => 'active',
        ]);
        $brandB = Brand::create([
            'domain' => 'gov-b-'.uniqid().'.ca',
            'slug' => 'gov-b-'.uniqid(),
            'company_name' => 'Gov Brand B',
            'status' => 'active',
        ]);

        $pm = $this->makePm('Scoped PM');
        PmBrandAssignment::create(['user_id' => $pm->id, 'brand_id' => $brandA->id]);

        $otherPm = $this->makePm('Other PM');
        $outLead = Lead::create([
            'contact_name' => 'Outside Brand Lead',
            'email' => 'out-'.uniqid().'@test.local',
            'phone' => '6045550444',
            'address' => '1 Outside Rd',
            'service_category' => 'drywall_paint',
            'status' => 'new',
            'assigned_pm_id' => $otherPm->id,
            'brand_id' => $brandB->id,
        ]);

        Sanctum::actingAs($pm);
        $res = $this->postJson('/api/ai/actions/run', [
            'action_key' => 'command_center_create_next_action',
            'lead_id' => $outLead->id,
            'confirmed' => true,
            'message' => 'should not run',
        ]);

        $res->assertOk();
        $this->assertSame('blocked', $res->json('status'));
        $this->assertSame('brand_scope', $res->json('decision.status'));
    }

    /** TC-6: Simulation produces proposed log without live changes. */
    public function test_6_simulation_no_live_changes(): void
    {
        $owner = $this->owner();
        $before = AiActionLog::count();

        Sanctum::actingAs($owner);
        $res = $this->postJson('/api/ai/actions/run', [
            'action_key' => 'send_customer_message',
            'message' => 'Would send this in live mode',
            'simulate' => true,
            'recipient_user_id' => $owner->id,
        ]);

        $res->assertOk();
        $this->assertSame('simulated', $res->json('status'));
        $this->assertTrue((bool) $res->json('decision.is_simulation'));
        $this->assertSame($before + 1, AiActionLog::count());
        $this->assertDatabaseHas('ai_action_logs', [
            'action_key' => 'send_customer_message',
            'outcome' => 'simulated',
            'is_simulation' => 1,
        ]);
    }

    /** Retention purge is monitored (last_purge_at recorded). */
    public function test_retention_purge_is_monitored(): void
    {
        Setting::set(AiConversationLogger::RETENTION_SETTING, '7');

        $brand = Brand::where('domain', 'acuteradrywall.ca')->firstOrFail();
        $session = \App\Models\IntakeSession::create([
            'brand_id' => $brand->id,
            'session_token' => \Illuminate\Support\Str::random(64),
            'conversation_state' => ['messages' => []],
            'expires_at' => now()->addDay(),
        ]);

        AiConversationLog::create([
            'intake_session_id' => $session->id,
            'turn_number' => 1,
            'role' => 'user',
            'content' => 'old',
            'created_at' => now()->subDays(30),
        ]);

        Artisan::call('learning:purge-ai-conversation-logs');

        $this->assertNotEmpty(Setting::get('ai_conversation_last_purge_at'));
        $this->assertNotNull(Setting::get('ai_conversation_last_purge_count'));
    }
}
