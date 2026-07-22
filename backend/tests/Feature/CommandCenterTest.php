<?php

namespace Tests\Feature;

use App\Models\AiActionLog;
use App\Models\AiCommandMessage;
use App\Models\AiCommandSession;
use App\Models\Invoice;
use App\Models\Job;
use App\Models\Lead;
use App\Models\NextAction;
use App\Models\Quote;
use App\Models\Setting;
use App\Models\User;
use App\Services\CommandCenter\CommandCenterQueryService;
use App\Services\PayoutEligibilityService;
use Database\Seeders\Milestone4Seeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class CommandCenterTest extends TestCase
{
    use DatabaseTransactions;

    public function createApplication()
    {
        $app = parent::createApplication();
        $app['config']->set('database.default', 'mysql');
        $app['config']->set('database.connections.mysql.database', 'hsop_job_command');
        $app['config']->set('payment.provider', 'mock');
        $app['config']->set('ai.provider', 'mock');

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(Milestone4Seeder::class);
        Setting::set('gst_rate', '5');
        Setting::set('split_contractor_pct', '80');
        Setting::set('split_pm_pct', '10');
        Setting::set('split_company_pct', '10');
        Setting::set('invoice_number_format', 'INV-{XXXX}');
        Setting::set('invoice_number_next', (string) random_int(300, 900));
        Setting::set('payout_schedule_business_days', '2');
        Setting::set('ai_kill_switch', 'false');
    }

    private function owner(): User
    {
        return User::where('role', 'owner')->first()
            ?: User::factory()->create(['role' => 'owner']);
    }

    private function seedStuckLeadAndEligibleJob(): array
    {
        $owner = $this->owner();
        $pm = User::create([
            'name' => 'CC Stuck PM',
            'email' => 'cc-pm-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
            'role' => 'pm',
            'status' => 'active',
            'phone' => '6045550111',
        ]);
        $contractor = User::create([
            'name' => 'CC Contractor',
            'email' => 'cc-con-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
            'role' => 'contractor',
            'status' => 'active',
        ]);
        $customer = User::create([
            'name' => 'CC Customer',
            'email' => 'cc-cust-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
            'role' => 'customer',
            'status' => 'active',
        ]);

        $lead = Lead::create([
            'contact_name' => 'Coquitlam Drywall',
            'email' => $customer->email,
            'phone' => '6045550222',
            'address' => '100 Coquitlam Drywall Rd',
            'service_category' => 'drywall_paint',
            'status' => 'new',
            'assigned_pm_id' => $pm->id,
            'customer_id' => $customer->id,
        ]);

        NextAction::create([
            'subject_type' => $lead->getMorphClass(),
            'subject_id' => $lead->id,
            'action_description' => 'Follow up on Coquitlam drywall lead',
            'responsible_role' => 'pm',
            'responsible_user_id' => $pm->id,
            'due_at' => now()->subHours(6),
            'status' => 'pending',
            'last_action_at' => now()->subDay(),
            'escalation_rule' => 'phase3_test',
        ]);

        $job = Job::create([
            'lead_id' => $lead->id,
            'customer_id' => $customer->id,
            'contractor_id' => $contractor->id,
            'pm_id' => $pm->id,
            'address' => '200 Ready Payout Ave',
            'service_category' => 'drywall_paint',
            'status' => 'payment_pending',
            'scope_of_work' => 'Command center payout job',
            'contractor_submitted_price' => 1000,
            'customer_accepted_completion_at' => now()->subDay(),
        ]);

        Quote::create([
            'job_id' => $job->id,
            'lead_id' => $lead->id,
            'customer_id' => $customer->id,
            'status' => 'approved',
            'scope_of_work' => 'Command center payout job',
            'contractor_base_price' => 1000,
            'customer_price_before_gst' => 1250,
            'gst_rate' => 5,
            'gst' => 62.50,
            'customer_total' => 1312.50,
            'contractor_pct' => 80,
            'pm_pct' => 10,
            'company_pct' => 10,
            'pm_amount' => 125,
            'company_amount' => 125,
            'sent_at' => now(),
        ]);

        Invoice::create([
            'job_id' => $job->id,
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-CC-'.uniqid(),
            'subtotal' => 1250,
            'gst_rate' => 5,
            'gst' => 62.50,
            'amount' => 1312.50,
            'balance' => 0,
            'amount_paid' => 1312.50,
            'status' => 'paid',
            'payment_date' => now()->toDateString(),
            'payment_method' => 'mock',
        ]);

        return compact('owner', 'pm', 'contractor', 'customer', 'lead', 'job');
    }

    public function test_owner_can_ask_and_persist_history(): void
    {
        $ctx = $this->seedStuckLeadAndEligibleJob();
        $this->actingAs($ctx['owner'], 'sanctum');

        $ask = $this->postJson('/api/command-center/ask', [
            'message' => 'How are things going today?',
        ]);
        $ask->assertOk();
        $sessionId = $ask->json('session.id');
        $this->assertNotEmpty($ask->json('assistant_message.content'));
        $this->assertDatabaseHas('ai_command_sessions', ['id' => $sessionId, 'user_id' => $ctx['owner']->id]);
        $this->assertSame(2, AiCommandMessage::where('session_id', $sessionId)->count());

        $show = $this->getJson("/api/command-center/sessions/{$sessionId}");
        $show->assertOk();
        $this->assertCount(2, $show->json('messages'));

        $this->assertDatabaseHas('ai_action_logs', [
            'trigger_event' => 'admin_command_center',
            'actor_id' => $ctx['owner']->id,
            'action_taken' => 'command_center_chat',
        ]);
    }

    public function test_example_queries_match_live_data(): void
    {
        $ctx = $this->seedStuckLeadAndEligibleJob();
        $queries = app(CommandCenterQueryService::class);

        $stuck = $queries->stuckLeads();
        $this->assertGreaterThanOrEqual(1, $stuck['count']);
        $this->assertTrue(collect($stuck['items'])->contains(fn ($i) => (int) $i['lead_id'] === (int) $ctx['lead']->id));

        $payout = $queries->jobsReadyForPayout();
        $this->assertGreaterThanOrEqual(1, $payout['count']);
        $this->assertTrue(collect($payout['jobs'])->contains(fn ($j) => (int) $j['job_id'] === (int) $ctx['job']->id));

        $check = app(PayoutEligibilityService::class)->checkEligibility($ctx['job']->fresh(['invoice']));
        $this->assertTrue($check['eligible']);

        $summary = $queries->todayOpsSummary();
        $this->assertSame(
            Lead::whereDate('created_at', today())->count(),
            $summary['new_leads_today']
        );
        $this->assertSame(
            Job::whereIn('status', [
                'scheduled', 'in_progress', 'update_posted', 'progress_updated', 'revision_in_progress',
            ])->count(),
            $summary['jobs_in_progress']
        );

        $this->actingAs($ctx['owner'], 'sanctum');

        $stuckAsk = $this->postJson('/api/command-center/ask', ['message' => 'Any leads stuck?']);
        $stuckAsk->assertOk();
        $this->assertStringContainsString((string) $stuck['count'], $stuckAsk->json('assistant_message.content'));
        $this->assertStringContainsString('Coquitlam', $stuckAsk->json('assistant_message.content'));

        $payoutAsk = $this->postJson('/api/command-center/ask', [
            'message' => 'What jobs are ready for payout?',
            'session_id' => $stuckAsk->json('session.id'),
        ]);
        $payoutAsk->assertOk();
        $this->assertStringContainsString((string) $payout['count'], $payoutAsk->json('assistant_message.content'));
        $this->assertStringContainsString('200 Ready Payout Ave', $payoutAsk->json('assistant_message.content'));
    }

    public function test_draft_pm_message_requires_confirm_and_logs(): void
    {
        $ctx = $this->seedStuckLeadAndEligibleJob();
        $this->actingAs($ctx['owner'], 'sanctum');

        $ask = $this->postJson('/api/command-center/ask', [
            'message' => 'Please message the PM about the overdue lead',
        ]);
        $ask->assertOk();
        $pending = $ask->json('pending_action');
        $this->assertNotNull($pending);
        $this->assertSame('draft_message_to_pm', $pending['type']);
        $this->assertDatabaseHas('ai_action_logs', [
            'trigger_event' => 'admin_command_center',
            'action_taken' => 'draft_message_to_pm',
            'decision' => 'draft_pending_approval',
        ]);

        $confirm = $this->postJson('/api/command-center/confirm', [
            'session_id' => $ask->json('session.id'),
            'pending_action' => $pending,
        ]);
        $confirm->assertOk();
        $this->assertSame('executed', $confirm->json('result.status'));
        $this->assertDatabaseHas('ai_action_logs', [
            'trigger_event' => 'admin_command_center',
            'action_taken' => 'send_pm_message',
            'decision' => 'executed',
        ]);
    }

    public function test_kill_switch_blocks_actions_not_queries(): void
    {
        $ctx = $this->seedStuckLeadAndEligibleJob();
        Setting::set('ai_kill_switch', 'true');
        $this->actingAs($ctx['owner'], 'sanctum');

        $q = $this->postJson('/api/command-center/ask', [
            'message' => 'How are things going today?',
        ]);
        $q->assertOk();
        $this->assertNotEmpty($q->json('assistant_message.content'));
        $this->assertTrue($q->json('assistant_message.meta.kill_switch'));

        $act = $this->postJson('/api/command-center/ask', [
            'message' => 'Please message the PM about follow-up',
            'session_id' => $q->json('session.id'),
        ]);
        $act->assertOk();
        $this->assertNull($act->json('pending_action'));
        $this->assertStringContainsStringIgnoringCase('kill switch', $act->json('assistant_message.content'));
    }

    public function test_non_owner_roles_forbidden(): void
    {
        $ctx = $this->seedStuckLeadAndEligibleJob();

        foreach (['pm', 'contractor', 'customer'] as $role) {
            $user = User::create([
                'name' => "CC {$role}",
                'email' => "cc-{$role}-".uniqid().'@test.local',
                'password' => bcrypt('password'),
                'role' => $role,
                'status' => 'active',
            ]);
            $this->actingAs($user, 'sanctum');
            $this->getJson('/api/command-center/sessions')->assertStatus(403);
            $this->postJson('/api/command-center/ask', ['message' => 'How are things going today?'])->assertStatus(403);
        }

        // Owner still OK
        $this->actingAs($ctx['owner'], 'sanctum');
        $this->getJson('/api/command-center/sessions')->assertOk();
    }
}
