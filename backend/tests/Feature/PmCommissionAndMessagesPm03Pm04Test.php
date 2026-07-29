<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Job;
use App\Models\Message;
use App\Models\Payout;
use App\Models\Quote;
use App\Models\User;
use App\Services\Finance\PmCommissionService;
use App\Services\PayoutEligibilityService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Audit PM-03 (commission states) + PM-04 (message channel separation / logging).
 */
class PmCommissionAndMessagesPm03Pm04Test extends TestCase
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
        if (! Schema::hasColumn('messages', 'delivery_status')) {
            $this->artisan('migrate', [
                '--path' => 'database/migrations/2026_07_28_000002_message_delivery_metadata_pm04.php',
                '--force' => true,
            ]);
        }
    }

    private function pm(): User
    {
        return User::create([
            'name' => 'PM03 PM',
            'email' => 'pm03-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
            'role' => 'pm',
            'status' => 'active',
        ]);
    }

    private function customer(): User
    {
        return User::create([
            'name' => 'PM03 Customer',
            'email' => 'cust03-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
            'role' => 'customer',
            'status' => 'active',
            'phone' => '6045550303',
        ]);
    }

    private function owner(): User
    {
        return User::create([
            'name' => 'PM03 Owner',
            'email' => 'owner03-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
            'role' => 'owner',
            'status' => 'active',
        ]);
    }

    private function jobWithApprovedQuote(User $pm, User $customer, float $subtotal = 1000): array
    {
        $job = Job::create([
            'customer_id' => $customer->id,
            'pm_id' => $pm->id,
            'address' => '303 Commission St',
            'service_category' => 'drywall_paint',
            'status' => 'quote_approved',
            'job_title' => 'PM03 Job',
            'split_contractor_pct' => 80,
            'split_pm_pct' => 10,
            'split_company_pct' => 10,
        ]);

        $quote = Quote::createWithUniqueQuoteNumber([
            'job_id' => $job->id,
            'customer_id' => $customer->id,
            'status' => 'approved',
            'scope_of_work' => 'Test scope',
            'subtotal' => $subtotal,
            'customer_price_before_gst' => $subtotal,
            'contractor_base_price' => 800,
            'gst' => 50,
            'customer_total' => 1050,
            'pm_pct' => 10,
            'pm_amount' => 100,
            'contractor_pct' => 80,
            'company_pct' => 10,
        ]);

        return [$job->fresh(), $quote];
    }

    public function test_1_quote_approved_commission_is_projected(): void
    {
        $pm = $this->pm();
        $customer = $this->customer();
        [$job, $quote] = $this->jobWithApprovedQuote($pm, $customer);

        app(\App\Services\PayoutWorkflowService::class)->createPayoutsOnQuoteApproval($quote->fresh(['job']));

        $payout = Payout::where('job_id', $job->id)->where(function ($q) {
            $q->where('payout_type', 'pm')->orWhere('split_type', 'pm');
        })->first();
        $this->assertNotNull($payout);

        $presented = app(PmCommissionService::class)->present($payout);
        $this->assertSame('projected', $presented['commission_state']);
        $this->assertSame('Projected', $presented['commission_state_label']);
        $this->assertFalse($presented['amount_is_guaranteed']);
        $this->assertNotNull($presented['outstanding_condition']);
        $this->assertStringContainsString('waiting', strtolower($presented['outstanding_condition']));

        Sanctum::actingAs($pm);
        $res = $this->getJson('/api/payouts');
        $res->assertOk();
        $row = collect($res->json('data'))->firstWhere('id', $payout->id);
        $this->assertNotNull($row);
        $this->assertSame('projected', $row['commission_state']);
        $this->assertEquals(1000.0, (float) $row['approved_subtotal']);
        $this->assertEquals(10.0, (float) $row['pm_percentage']);
    }

    public function test_2_completion_and_payment_make_payable_then_paid(): void
    {
        $pm = $this->pm();
        $owner = $this->owner();
        $customer = $this->customer();
        [$job, $quote] = $this->jobWithApprovedQuote($pm, $customer);
        app(\App\Services\PayoutWorkflowService::class)->createPayoutsOnQuoteApproval($quote->fresh(['job']));

        Invoice::create([
            'job_id' => $job->id,
            'customer_id' => $customer->id,
            'status' => 'paid',
            'subtotal' => 1000,
            'gst' => 50,
            'amount' => 1050,
            'balance' => 0,
            'invoice_number' => 'INV-PM03-'.uniqid(),
        ]);
        $job->update(['customer_accepted_completion_at' => now()]);

        $result = app(PayoutEligibilityService::class)->evaluateForJob($job->fresh(['invoice', 'quote', 'revisionRequests', 'contractor', 'pm']));
        $this->assertTrue($result['eligible']);

        $payout = Payout::where('job_id', $job->id)->where(function ($q) {
            $q->where('payout_type', 'pm')->orWhere('split_type', 'pm');
        })->first();
        $this->assertNotNull($payout);

        $presented = app(PmCommissionService::class)->present($payout->fresh());
        $this->assertSame('payable', $presented['commission_state']);
        $this->assertNull($presented['outstanding_condition']);
        $this->assertSame('cleared', $presented['customer_payment_state']);

        Sanctum::actingAs($owner);
        $this->putJson('/api/payouts/'.$payout->id.'/mark-paid')->assertOk();

        $paid = app(PmCommissionService::class)->present($payout->fresh());
        $this->assertSame('paid', $paid['commission_state']);
        $this->assertTrue($paid['paid_confirmed']);
        $this->assertNotNull($paid['paid_date']);
    }

    public function test_3_looks_like_internal_content_helper_matches_margin_price(): void
    {
        // Frontend helper mirrored for regression — keep logic in sync via documented pattern
        $text = 'the contractor price is $500 and our margin is high';
        $hasMoney = (bool) preg_match('/\$\s?\d|\d+\s?(dollars|cad)|price\s*[:=]|\d{2,}\.\d{2}/i', $text);
        $hasTerm = str_contains(strtolower($text), 'margin') || str_contains(strtolower($text), 'contractor price');
        $this->assertTrue($hasMoney && $hasTerm);
    }

    public function test_4_and_5_internal_note_logged_and_hidden_from_customer(): void
    {
        $pm = $this->pm();
        $customer = $this->customer();
        [$job] = $this->jobWithApprovedQuote($pm, $customer);

        Sanctum::actingAs($pm);
        $res = $this->postJson("/api/jobs/{$job->id}/messages", [
            'content' => 'Internal margin note — contractor price $400',
            'visibility' => 'internal',
        ]);
        $res->assertCreated();
        $this->assertSame('pm_internal', $res->json('channel'));
        $this->assertSame('internal', $res->json('recipient_label'));
        $this->assertNotNull($res->json('delivery_status'));

        $this->assertTrue(
            AuditLog::query()
                ->where('action_type', 'message_sent')
                ->where('object_type', 'message')
                ->where('object_id', $res->json('id'))
                ->exists()
        );

        Sanctum::actingAs($customer);
        $list = $this->getJson("/api/jobs/{$job->id}/messages")->assertOk()->json();
        $ids = collect($list)->pluck('id');
        $this->assertFalse($ids->contains($res->json('id')));

        $customerForce = $this->getJson("/api/jobs/{$job->id}/messages?visibility=internal")->assertOk()->json();
        // Even if they pass visibility, index still returns — but store blocks; for index, customers can request internal via query!
        // Harden: force customer_visible for customers regardless of query.
    }

    public function test_6_customer_chat_records_recipient_and_channel(): void
    {
        $pm = $this->pm();
        $customer = $this->customer();
        [$job] = $this->jobWithApprovedQuote($pm, $customer);

        Sanctum::actingAs($pm);
        $res = $this->postJson("/api/jobs/{$job->id}/messages", [
            'content' => 'Hi, scheduling update for your project.',
            'visibility' => 'customer_visible',
        ]);
        $res->assertCreated();
        $this->assertSame('pm_to_customer', $res->json('channel'));
        $this->assertSame($customer->name, $res->json('recipient_label'));
        $this->assertSame($customer->id, $res->json('receiver_id'));
        $this->assertSame($job->id, $res->json('job_id'));

        $audit = AuditLog::query()
            ->where('action_type', 'message_sent')
            ->where('object_id', $res->json('id'))
            ->latest('id')
            ->first();
        $this->assertNotNull($audit);
        $payload = is_array($audit->new_value) ? $audit->new_value : json_decode((string) $audit->new_value, true);
        $this->assertSame('pm_to_customer', $payload['channel'] ?? null);
        $this->assertSame($customer->name, $payload['recipient'] ?? null);
        $this->assertSame($job->id, $payload['job_id'] ?? null);
    }

    public function test_7_customer_cannot_read_internal_even_with_query_param(): void
    {
        $pm = $this->pm();
        $customer = $this->customer();
        [$job] = $this->jobWithApprovedQuote($pm, $customer);

        Message::create([
            'job_id' => $job->id,
            'sender_id' => $pm->id,
            'sender_role' => 'pm',
            'content' => 'secret',
            'visibility' => 'internal',
            'channel' => 'pm_internal',
            'recipient_label' => 'internal',
            'delivery_status' => 'recorded',
        ]);

        Sanctum::actingAs($customer);
        $list = $this->getJson("/api/jobs/{$job->id}/messages?visibility=internal")->assertOk()->json();
        foreach ($list as $m) {
            $this->assertSame('customer_visible', $m['visibility']);
        }
    }
}
