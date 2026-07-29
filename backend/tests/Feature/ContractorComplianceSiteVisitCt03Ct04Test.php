<?php

namespace Tests\Feature;

use App\Models\Contractor;
use App\Models\ContractorDocument;
use App\Models\Job;
use App\Models\Lead;
use App\Models\SiteVisit;
use App\Models\SiteVisitPhoto;
use App\Models\SiteVisitSubmission;
use App\Models\User;
use App\Services\Contractors\ContractorProfileCompleteness;
use Database\Seeders\Milestone4Seeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ContractorComplianceSiteVisitCt03Ct04Test extends TestCase
{
    use DatabaseTransactions;

    public function createApplication()
    {
        $app = parent::createApplication();
        $app['config']->set('database.default', 'mysql');
        $app['config']->set('database.connections.mysql.database', 'hsop_job_command');
        $app['config']->set('filesystems.uploads_disk', 'public');
        $app['config']->set('payment.provider', 'mock');

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->seed(Milestone4Seeder::class);
        \App\Models\Setting::set('sms_globally_enabled', 'false');
        \App\Models\Setting::set('email_globally_enabled', 'false');
    }

    private function makeContractorContext(): array
    {
        $owner = User::where('role', 'owner')->first() ?: User::factory()->create(['role' => 'owner']);
        $pm = User::create([
            'name' => 'CT PM '.uniqid(), 'email' => 'ct-pm-'.uniqid().'@test.local',
            'password' => bcrypt('password'), 'role' => 'pm', 'status' => 'active', 'phone' => '6045550301',
        ]);
        $contractorUser = User::create([
            'name' => 'CT Contractor '.uniqid(), 'email' => 'ct-con-'.uniqid().'@test.local',
            'password' => bcrypt('password'), 'role' => 'contractor', 'status' => 'active', 'phone' => '6045550302',
        ]);
        $customer = User::create([
            'name' => 'CT Customer', 'email' => 'ct-cust-'.uniqid().'@test.local',
            'password' => bcrypt('password'), 'role' => 'customer', 'status' => 'active', 'phone' => '6045550303',
        ]);

        $contractor = app(ContractorProfileCompleteness::class)->ensureProfileForUser($contractorUser);

        $lead = Lead::create([
            'contact_name' => $customer->name,
            'email' => $customer->email,
            'phone' => $customer->phone,
            'address' => '400 CT Test Ave',
            'service_category' => 'drywall_paint',
            'status' => 'site_visit_scheduled',
            'source' => 'website',
            'assigned_pm_id' => $pm->id,
            'site_visit_contractor_id' => $contractorUser->id,
            'assigned_contractor_id' => $contractorUser->id,
            'customer_id' => $customer->id,
            'customer_portal_token' => Str::random(64),
        ]);

        $siteVisit = SiteVisit::create([
            'lead_id' => $lead->id,
            'pm_id' => $pm->id,
            'contractor_id' => $contractorUser->id,
            'customer_id' => $customer->id,
            'visit_date' => now()->addDays(2)->toDateString(),
            'visit_time' => '10:00',
            'status' => 'scheduled',
        ]);

        return compact('owner', 'pm', 'contractorUser', 'contractor', 'customer', 'lead', 'siteVisit');
    }

    // ── CT-03 Tests ──────────────────────────────────────────────────────

    /** @test 1: Upload a WCB document → status moves to "pending_review". */
    public function test_1_upload_wcb_document_moves_to_pending_review(): void
    {
        $ctx = $this->makeContractorContext();
        $file = UploadedFile::fake()->create('wcb.pdf', 100, 'application/pdf');

        $resp = $this->actingAs($ctx['contractorUser'])
            ->post("/api/contractors/{$ctx['contractor']->id}/documents", [
                'document_type' => 'wcb',
                'document' => $file,
                'expiry_date' => now()->addYear()->toDateString(),
            ])
            ->assertCreated()
            ->assertJsonPath('document.status', 'pending_review');

        $ctx['contractor']->refresh();
        $this->assertSame('pending_review', $ctx['contractor']->wcb_status);

        $queue = $this->actingAs($ctx['owner'])->getJson('/api/compliance/pending-review');
        $queue->assertOk();
        $this->assertGreaterThanOrEqual(1, count($queue->json()));
    }

    /** @test 2: Owner approves → wcb_status = approved, missing_steps updated. */
    public function test_2_owner_approves_document_updates_status_and_missing_steps(): void
    {
        $ctx = $this->makeContractorContext();
        $file = UploadedFile::fake()->create('wcb.pdf', 100, 'application/pdf');

        $upload = $this->actingAs($ctx['contractorUser'])
            ->post("/api/contractors/{$ctx['contractor']->id}/documents", [
                'document_type' => 'wcb',
                'document' => $file,
            ]);

        $docId = $upload->json('document.id');

        $this->actingAs($ctx['owner'])
            ->put("/api/contractors/{$ctx['contractor']->id}/documents/{$docId}/review", [
                'status' => 'approved',
            ])
            ->assertOk();

        $ctx['contractor']->refresh();
        $this->assertSame('approved', $ctx['contractor']->wcb_status);

        $keys = array_column($ctx['contractor']->missing_steps, 'key');
        $this->assertNotContains('wcb', $keys);
    }

    /** @test 3: Owner rejects with reason → contractor sees reason + can re-upload. */
    public function test_3_owner_rejects_with_reason_contractor_sees_and_reuploads(): void
    {
        $ctx = $this->makeContractorContext();
        $file = UploadedFile::fake()->create('insurance.pdf', 100, 'application/pdf');

        $upload = $this->actingAs($ctx['contractorUser'])
            ->post("/api/contractors/{$ctx['contractor']->id}/documents", [
                'document_type' => 'liability_insurance',
                'document' => $file,
            ]);
        $docId = $upload->json('document.id');

        $this->actingAs($ctx['owner'])
            ->put("/api/contractors/{$ctx['contractor']->id}/documents/{$docId}/review", [
                'status' => 'rejected',
                'rejection_reason' => 'Document is expired — upload current version.',
            ])
            ->assertOk();

        $docs = $this->actingAs($ctx['contractorUser'])
            ->getJson("/api/contractors/{$ctx['contractor']->id}/documents")
            ->assertOk();

        $rejected = collect($docs->json())->firstWhere('id', $docId);
        $this->assertSame('rejected', $rejected['status']);
        $this->assertSame('Document is expired — upload current version.', $rejected['rejection_reason']);

        // Re-upload
        $file2 = UploadedFile::fake()->create('insurance-new.pdf', 100, 'application/pdf');
        $this->actingAs($ctx['contractorUser'])
            ->post("/api/contractors/{$ctx['contractor']->id}/documents", [
                'document_type' => 'liability_insurance',
                'document' => $file2,
            ])
            ->assertCreated();
    }

    /** @test 4: Expiry date 5 days out → "expiring_soon" computed status. */
    public function test_4_expiry_date_5_days_out_shows_expiring_soon(): void
    {
        $ctx = $this->makeContractorContext();
        $file = UploadedFile::fake()->create('wcb.pdf', 100, 'application/pdf');

        $upload = $this->actingAs($ctx['contractorUser'])
            ->post("/api/contractors/{$ctx['contractor']->id}/documents", [
                'document_type' => 'wcb',
                'document' => $file,
                'expiry_date' => now()->addDays(5)->toDateString(),
            ]);
        $docId = $upload->json('document.id');

        $this->actingAs($ctx['owner'])
            ->put("/api/contractors/{$ctx['contractor']->id}/documents/{$docId}/review", ['status' => 'approved']);

        $docs = $this->actingAs($ctx['contractorUser'])
            ->getJson("/api/contractors/{$ctx['contractor']->id}/documents")
            ->assertOk();

        $doc = collect($docs->json())->firstWhere('id', $docId);
        $this->assertSame('expiring_soon', $doc['computed_status']);

        // Dashboard also reflects it.
        $dash = $this->actingAs($ctx['contractorUser'])->getJson('/api/dashboard/contractor/kpis')->assertOk();
        $this->assertSame('expiring_soon', $dash->json('document_status.wcb'));
    }

    // ── CT-04 Tests ──────────────────────────────────────────────────────

    /** @test 5: Full site-visit workflow: accept → photos → measurements → submit price → PM notified. */
    public function test_5_full_site_visit_workflow(): void
    {
        $ctx = $this->makeContractorContext();
        $sv = $ctx['siteVisit'];
        $con = $ctx['contractorUser'];

        // Accept
        $this->actingAs($con)->postJson("/api/site-visits/{$sv->id}/accept")->assertOk();
        $sv->refresh();
        $this->assertSame('accepted', $sv->status);
        $this->assertNotNull($sv->accepted_at);

        // Show returns full detail
        $show = $this->actingAs($con)->getJson("/api/site-visits/{$sv->id}")->assertOk();
        $this->assertNotNull($show->json('directions_url'));
        $this->assertSame($ctx['pm']->id, $show->json('pm.id'));

        // Upload photo
        $photo = UploadedFile::fake()->create('site.jpg', 100, 'image/jpeg');
        $this->actingAs($con)->post("/api/site-visits/{$sv->id}/photos", ['photo' => $photo])->assertCreated();

        // Save draft
        $this->actingAs($con)->postJson("/api/site-visits/{$sv->id}/draft", [
            'labour_estimate' => '16 hours',
            'crew_size' => '2',
            'materials_notes' => '10 sheets drywall',
        ])->assertOk();

        $sub = SiteVisitSubmission::where('site_visit_id', $sv->id)->first();
        $this->assertNotNull($sub);
        $this->assertSame('draft', $sub->status);

        // Submit price
        $this->actingAs($con)->postJson("/api/site-visits/{$sv->id}/submit-price", [
            'contractor_price' => 1500,
            'price_notes' => 'Standard drywall + paint',
        ])->assertOk();

        $sub->refresh();
        $this->assertSame('submitted', $sub->status);
        $this->assertSame('1500.00', $sub->contractor_price);
        $this->assertNotNull($sub->price_submitted_at);

        // Lead price also updated
        $ctx['lead']->refresh();
        $this->assertEquals(1500, $ctx['lead']->contractor_price);

        // Photo is linked
        $this->assertSame(1, SiteVisitPhoto::where('lead_id', $ctx['lead']->id)->count());
    }

    /** @test 6: Duplicate price submission blocked. */
    public function test_6_duplicate_price_submission_blocked(): void
    {
        $ctx = $this->makeContractorContext();
        $sv = $ctx['siteVisit'];
        $con = $ctx['contractorUser'];

        $this->actingAs($con)->postJson("/api/site-visits/{$sv->id}/accept");
        $this->actingAs($con)->postJson("/api/site-visits/{$sv->id}/submit-price", [
            'contractor_price' => 1500,
        ])->assertOk();

        $this->actingAs($con)->postJson("/api/site-visits/{$sv->id}/submit-price", [
            'contractor_price' => 2000,
        ])->assertStatus(422)->assertJsonFragment(['message' => 'Price already submitted for this visit. Use the revise action if the PM requests changes.']);
    }

    /** @test 7: Save draft, leave, come back — progress saved. */
    public function test_7_save_draft_preserves_progress(): void
    {
        $ctx = $this->makeContractorContext();
        $sv = $ctx['siteVisit'];
        $con = $ctx['contractorUser'];

        $this->actingAs($con)->postJson("/api/site-visits/{$sv->id}/accept");

        $this->actingAs($con)->postJson("/api/site-visits/{$sv->id}/draft", [
            'labour_estimate' => '8 hours',
            'crew_size' => '1',
            'assumptions' => 'Standard ceiling height',
        ])->assertOk();

        // "come back" — fetch detail
        $show = $this->actingAs($con)->getJson("/api/site-visits/{$sv->id}")->assertOk();
        $this->assertSame('draft', $show->json('submission.status'));
        $this->assertSame('8 hours', $show->json('submission.labour_estimate'));
        $this->assertSame('Standard ceiling height', $show->json('submission.assumptions'));
    }

    /** @test 8: Contractor price never exposed via customer endpoint. */
    public function test_8_contractor_price_not_exposed_to_customer(): void
    {
        $ctx = $this->makeContractorContext();
        $sv = $ctx['siteVisit'];
        $con = $ctx['contractorUser'];

        $this->actingAs($con)->postJson("/api/site-visits/{$sv->id}/accept");
        $this->actingAs($con)->postJson("/api/site-visits/{$sv->id}/submit-price", [
            'contractor_price' => 1500,
        ]);

        // Customer tries to access site visit
        $this->actingAs($ctx['customer'])->getJson("/api/site-visits/{$sv->id}")->assertStatus(403);

        // Customer dashboard should NOT show contractor price
        $dash = $this->actingAs($ctx['customer'])->getJson('/api/dashboard/customer/summary')->assertOk();
        $dashJson = json_encode($dash->json());
        $this->assertStringNotContainsString('1500', $dashJson);
    }

    /** @test 9: Convert lead to job — photos carry forward. */
    public function test_9_convert_lead_photos_carry_forward(): void
    {
        $ctx = $this->makeContractorContext();
        $sv = $ctx['siteVisit'];
        $con = $ctx['contractorUser'];

        $this->actingAs($con)->postJson("/api/site-visits/{$sv->id}/accept");

        $photo = UploadedFile::fake()->create('site-photo.jpg', 100, 'image/jpeg');
        $this->actingAs($con)->post("/api/site-visits/{$sv->id}/photos", ['photo' => $photo])->assertCreated();

        $this->actingAs($con)->postJson("/api/site-visits/{$sv->id}/submit-price", [
            'contractor_price' => 800,
        ]);

        $photoBeforeConvert = SiteVisitPhoto::where('lead_id', $ctx['lead']->id)->first();
        $this->assertNotNull($photoBeforeConvert);
        $this->assertNull($photoBeforeConvert->job_id);

        $convert = $this->actingAs($ctx['owner'])->postJson("/api/leads/{$ctx['lead']->id}/convert-to-job");
        $convert->assertCreated();
        $jobId = $convert->json('job_id');

        $photoBeforeConvert->refresh();
        $this->assertSame($jobId, $photoBeforeConvert->job_id);
    }

    /** @test 10: PM requests revision → contractor can resubmit. */
    public function test_10_pm_revision_request_and_contractor_resubmit(): void
    {
        $ctx = $this->makeContractorContext();
        $sv = $ctx['siteVisit'];
        $con = $ctx['contractorUser'];

        $this->actingAs($con)->postJson("/api/site-visits/{$sv->id}/accept");
        $this->actingAs($con)->postJson("/api/site-visits/{$sv->id}/submit-price", [
            'contractor_price' => 1500,
        ]);

        // PM requests revision
        $this->actingAs($ctx['pm'])->postJson("/api/site-visits/{$sv->id}/request-revision")
            ->assertOk();

        $sub = SiteVisitSubmission::where('site_visit_id', $sv->id)->first();
        $this->assertSame('revision_requested', $sub->status);

        // Contractor revises
        $this->actingAs($con)->postJson("/api/site-visits/{$sv->id}/revise", [
            'contractor_price' => 1700,
            'price_notes' => 'Added extra materials',
        ])->assertOk();

        $sub->refresh();
        $this->assertSame('revised', $sub->status);
        $this->assertSame('1700.00', $sub->contractor_price);
    }
}
