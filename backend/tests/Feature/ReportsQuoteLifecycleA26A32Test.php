<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Job;
use App\Models\NextAction;
use App\Models\Quote;
use App\Models\User;
use App\Services\Finance\FinancialLedgerService;
use App\Services\Workflow\EscalationEngine;
use App\Services\Workflow\QuoteLifecycleService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A-26 (remaining charts) + A-32 quote lifecycle.
 */
class ReportsQuoteLifecycleA26A32Test extends TestCase
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
        if (Schema::hasTable('quotes') && ! Schema::hasColumn('quotes', 'revision_number')) {
            $this->artisan('migrate', [
                '--path' => 'database/migrations/2026_07_29_000009_a26_a32_reports_quote_lifecycle.php',
                '--force' => true,
            ]);
        }
    }

    private function owner(): User
    {
        return User::create([
            'name' => 'A32 Owner',
            'email' => 'a32-owner-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
            'role' => 'owner',
            'status' => 'active',
        ]);
    }

    private function customer(): User
    {
        return User::create([
            'name' => 'A32 Cust',
            'email' => 'a32-cust-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
            'role' => 'customer',
            'status' => 'active',
        ]);
    }

    private function pm(): User
    {
        return User::create([
            'name' => 'A32 PM',
            'email' => 'a32-pm-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
            'role' => 'pm',
            'status' => 'active',
        ]);
    }

    private function makeQuote(array $attrs = []): array
    {
        $customer = $this->customer();
        $pm = $this->pm();
        $job = Job::create([
            'customer_id' => $customer->id,
            'pm_id' => $pm->id,
            'status' => 'quote_sent',
            'address' => '32 Quote Lane',
            'is_test_data' => false,
        ]);
        $quote = Quote::create(array_merge([
            'job_id' => $job->id,
            'customer_id' => $customer->id,
            'quote_number' => 'QT-A32-'.uniqid(),
            'revision_number' => 1,
            'root_quote_id' => null,
            'scope_of_work' => 'Patch and paint',
            'contractor_base_price' => 800,
            'customer_price_before_gst' => 1000,
            'subtotal' => 1000,
            'hsop_markup' => 200,
            'gst_enabled' => true,
            'gst_rate' => 5,
            'gst' => 50,
            'customer_total' => 1050,
            'status' => 'sent',
            'sent_at' => now()->subDays(3),
            'is_immutable' => true,
            'customer_token' => bin2hex(random_bytes(16)),
            'pm_amount' => 100,
            'company_amount' => 100,
        ], $attrs));
        $quote->update(['root_quote_id' => $quote->root_quote_id ?: $quote->id]);

        return compact('customer', 'pm', 'job', 'quote');
    }

    /** @test TC1: revenue/jobs breakdown drill-down returns underlying period records */
    public function test_1_revenue_jobs_chart_drilldown_opens_underlying_records(): void
    {
        $owner = $this->owner();
        $ctx = $this->makeQuote(['status' => 'approved', 'accepted_at' => now()]);
        Invoice::create([
            'job_id' => $ctx['job']->id,
            'customer_id' => $ctx['customer']->id,
            'quote_id' => $ctx['quote']->id,
            'invoice_number' => 'INV-A32-'.uniqid(),
            'subtotal' => 1000,
            'gst' => 50,
            'amount' => 1050,
            'balance' => 0,
            'amount_paid' => 1050,
            'status' => 'paid',
        ]);

        Sanctum::actingAs($owner);
        $breakdown = $this->getJson('/api/ledger/drilldown?metric=revenue_jobs_breakdown&basis=accrual')
            ->assertOk()
            ->json();
        $this->assertNotEmpty($breakdown['records']);
        $period = $breakdown['records'][0]['period'];
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}$/', $period);

        $from = $period.'-01';
        $to = date('Y-m-t', strtotime($from));
        $drill = $this->getJson("/api/ledger/drilldown?metric=collected_revenue&basis=accrual&from={$from}&to={$to}")
            ->assertOk()
            ->json();
        $this->assertArrayHasKey('records', $drill);

        $report = $this->getJson('/api/reports/profit-breakdown?basis=accrual')->assertOk()->json();
        $this->assertArrayHasKey('revenue_jobs_breakdown', $report);
        $ledger = app(FinancialLedgerService::class)->summary(['basis' => 'accrual']);
        $this->assertEqualsWithDelta(
            (float) $ledger['collected_revenue'],
            (float) $report['collected_revenue'],
            0.01
        );
    }

    /** @test TC2: approve cancels pending follow-up task */
    public function test_2_approve_stops_follow_up_immediately(): void
    {
        $ctx = $this->makeQuote(['status' => 'viewed', 'viewed_at' => now()->subDay()]);
        $na = NextAction::create([
            'subject_type' => $ctx['job']->getMorphClass(),
            'subject_id' => $ctx['job']->id,
            'escalation_rule' => QuoteLifecycleService::FOLLOW_UP_RULE,
            'action_description' => 'Follow up with customer on quote #'.$ctx['quote']->id,
            'responsible_role' => 'pm',
            'responsible_user_id' => $ctx['pm']->id,
            'due_at' => now(),
            'status' => 'pending',
        ]);
        $ctx['quote']->update(['follow_up_due_at' => now()]);

        Sanctum::actingAs($ctx['customer']);
        $this->postJson('/api/quotes/'.$ctx['quote']->id.'/approve')->assertOk();

        $this->assertSame('approved', $ctx['quote']->fresh()->status);
        $this->assertSame('completed', $na->fresh()->status);
        $this->assertNotNull($ctx['quote']->fresh()->follow_up_stopped_at);
        $this->assertFalse(app(QuoteLifecycleService::class)->hasOpenFollowUp($ctx['quote']->fresh()));
    }

    /** @test TC3: revision preserves original sent version and increments revision number */
    public function test_3_revision_preserves_immutable_sent_version(): void
    {
        $owner = $this->owner();
        $ctx = $this->makeQuote([
            'status' => 'sent',
            'customer_total' => 1050,
            'scope_of_work' => 'Original scope',
        ]);
        $originalId = $ctx['quote']->id;
        $originalTotal = (float) $ctx['quote']->customer_total;
        $originalScope = $ctx['quote']->scope_of_work;

        Sanctum::actingAs($owner);
        $res = $this->postJson('/api/quotes/'.$originalId.'/revise')->assertCreated()->json();

        $original = Quote::find($originalId);
        $this->assertSame('revision_requested', $original->status);
        $this->assertTrue((bool) $original->is_immutable);
        $this->assertSame($originalTotal, (float) $original->customer_total);
        $this->assertSame($originalScope, $original->scope_of_work);
        $this->assertSame(1, (int) $original->revision_number);

        $revision = Quote::find($res['quote']['id']);
        $this->assertSame('draft', $revision->status);
        $this->assertSame(2, (int) $revision->revision_number);
        $this->assertSame($originalId, (int) $revision->parent_quote_id);
        $this->assertFalse((bool) $revision->is_immutable);

        // Mutating revision must not change original
        $revision->update(['customer_total' => 9999, 'scope_of_work' => 'Changed']);
        $this->assertSame($originalTotal, (float) $original->fresh()->customer_total);
        $this->assertSame($originalScope, $original->fresh()->scope_of_work);
    }

    /** @test TC4: filters by status/pm/date match exactly */
    public function test_4_quote_filters_match_criteria(): void
    {
        $owner = $this->owner();
        $match = $this->makeQuote(['status' => 'sent']);
        $other = $this->makeQuote(['status' => 'draft', 'is_immutable' => false, 'sent_at' => null]);

        Sanctum::actingAs($owner);
        $res = $this->getJson('/api/quotes?status=sent&pm_id='.$match['pm']->id)->assertOk()->json();
        $ids = collect($res['data'] ?? $res)->pluck('id')->all();
        $this->assertContains($match['quote']->id, $ids);
        $this->assertNotContains($other['quote']->id, $ids);

        foreach ($res['data'] ?? [] as $row) {
            $this->assertSame('sent', $row['status']);
        }
    }

    /** @test TC5: customer token view never exposes financial breakdown */
    public function test_5_customer_quote_view_hides_financial_breakdown(): void
    {
        $ctx = $this->makeQuote();
        $res = $this->getJson('/api/quote/view/'.$ctx['quote']->customer_token)->assertOk()->json();

        $this->assertEquals(1050, (float) $res['customer_total']);
        $this->assertArrayNotHasKey('contractor_base_price', $res);
        $this->assertArrayNotHasKey('pm_amount', $res);
        $this->assertArrayNotHasKey('company_amount', $res);
        $this->assertArrayNotHasKey('hsop_markup', $res);
        $this->assertArrayNotHasKey('margin', $res);
        $this->assertArrayNotHasKey('contractor_pct', $res);

        Sanctum::actingAs($ctx['customer']);
        $list = $this->getJson('/api/quotes')->assertOk()->json();
        $row = collect($list['data'] ?? $list)->firstWhere('id', $ctx['quote']->id);
        $this->assertNotNull($row);
        $this->assertArrayNotHasKey('contractor_base_price', $row);
        $this->assertArrayNotHasKey('margin', $row);
        $this->assertEquals(1050, (float) $row['customer_total']);
    }

    /** @test Follow-up escalation does not overwrite quote status */
    public function test_follow_up_is_task_not_status(): void
    {
        $ctx = $this->makeQuote([
            'status' => 'sent',
            'sent_at' => now()->subDays(5),
            'updated_at' => now()->subDays(5),
        ]);
        // Force updated_at for sweep query
        Quote::where('id', $ctx['quote']->id)->update(['updated_at' => now()->subDays(5)]);

        app(EscalationEngine::class)->run();

        $quote = $ctx['quote']->fresh();
        $this->assertSame('sent', $quote->status);
        $this->assertNotNull($quote->follow_up_due_at);
        $this->assertTrue(
            NextAction::query()
                ->where('subject_id', $ctx['job']->id)
                ->where('escalation_rule', QuoteLifecycleService::FOLLOW_UP_RULE)
                ->whereIn('status', ['pending', 'overdue', 'escalated'])
                ->exists()
        );
    }

    /** @test decline/expire/revise also stop follow-up */
    public function test_terminal_actions_stop_follow_up(): void
    {
        $owner = $this->owner();
        $lifecycle = app(QuoteLifecycleService::class);

        foreach (['expire', 'mark-declined', 'revise'] as $action) {
            $ctx = $this->makeQuote(['status' => 'viewed', 'viewed_at' => now()]);
            $na = NextAction::create([
                'subject_type' => $ctx['job']->getMorphClass(),
                'subject_id' => $ctx['job']->id,
                'escalation_rule' => QuoteLifecycleService::FOLLOW_UP_RULE,
                'action_description' => 'Follow up with customer on quote #'.$ctx['quote']->id,
                'responsible_role' => 'pm',
                'responsible_user_id' => $ctx['pm']->id,
                'due_at' => now(),
                'status' => 'pending',
            ]);
            $ctx['quote']->update(['follow_up_due_at' => now()]);

            Sanctum::actingAs($owner);
            $url = '/api/quotes/'.$ctx['quote']->id.'/'.$action;
            $body = $action === 'mark-declined' ? ['rejection_reason' => 'No budget'] : [];
            $this->postJson($url, $body)->assertSuccessful();

            $this->assertSame('completed', $na->fresh()->status, "Follow-up should stop after {$action}");
            $this->assertFalse($lifecycle->hasOpenFollowUp($ctx['quote']->fresh()));
        }
    }
}
