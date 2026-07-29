<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\ContentEditorBrandAssignment;
use App\Models\ContentRevision;
use App\Models\LocationPage;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A-36 — Agency-safe content permissions, workflow, preview, technical SEO.
 */
class BrandContentWorkflowA36Test extends TestCase
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
        if (! Schema::hasTable('content_revisions')) {
            $this->artisan('migrate', [
                '--path' => 'database/migrations/2026_07_30_000001_a36_content_workflow_seo.php',
                '--force' => true,
            ]);
        }
    }

    private function brand(string $slug = 'a36'): Brand
    {
        return Brand::create([
            'domain' => $slug.'-'.uniqid().'.test',
            'slug' => $slug.'-'.uniqid(),
            'company_name' => 'A36 '.$slug,
            'status' => 'active',
            'service_categories' => [['key' => 'drywall', 'label' => 'Drywall']],
        ]);
    }

    private function owner(): User
    {
        return User::create([
            'name' => 'A36 Owner',
            'email' => 'a36-owner-'.uniqid().'@test.local',
            'password' => Hash::make('password'),
            'role' => 'owner',
            'status' => 'active',
        ]);
    }

    private function editor(Brand $brand): User
    {
        $user = User::create([
            'name' => 'A36 Editor',
            'email' => 'a36-ed-'.uniqid().'@test.local',
            'password' => Hash::make('password'),
            'role' => 'content_editor',
            'brand_id' => $brand->id,
            'status' => 'active',
        ]);
        ContentEditorBrandAssignment::create([
            'user_id' => $user->id,
            'brand_id' => $brand->id,
            'assigned_at' => now(),
        ]);

        return $user;
    }

    /** TC1 — SEO role blocked from leads/pricing/AI/payments. */
    public function test_1_seo_role_blocked_from_ops(): void
    {
        $brand = $this->brand();
        $editor = $this->editor($brand);
        Sanctum::actingAs($editor);

        foreach (['/api/leads', '/api/pricing-rules', '/api/ai/settings', '/api/payment-destinations', '/api/invoices'] as $path) {
            $this->getJson($path)->assertForbidden();
        }
    }

    /** TC2 — cannot access unassigned brand. */
    public function test_2_seo_role_blocked_from_other_brand(): void
    {
        $mine = $this->brand('mine');
        $other = $this->brand('other');
        $editor = $this->editor($mine);
        Sanctum::actingAs($editor);

        // Requesting another brand_id is hard-blocked (PM-01/PM-02 style).
        $this->getJson('/api/brand-content?brand_id='.$other->id)->assertForbidden();

        // Assigned brand still works without query.
        $this->getJson('/api/brand-content')
            ->assertOk()
            ->assertJsonPath('id', $mine->id);
    }

    /** TC3 — draft → review → approve → publish; preview matches. */
    public function test_3_workflow_and_preview_match(): void
    {
        $brand = $this->brand();
        $editor = $this->editor($brand);
        $owner = $this->owner();

        Sanctum::actingAs($editor);
        $create = $this->postJson('/api/brand-content/locations', [
            'city_name' => 'Burnaby',
            'region' => 'BC',
            'content' => [
                'headline' => 'Drywall in Burnaby',
                'body' => 'We repair and finish drywall across Burnaby with clean edges and reliable scheduling for homeowners.',
                'cta_label' => 'Book a visit',
            ],
            'canonical_url' => 'https://example.test/locations/burnaby',
            'sitemap_include' => true,
            'sections' => [
                ['type' => 'faq', 'title' => 'FAQ', 'items' => [['q' => 'How long?', 'a' => '1-2 days']]],
            ],
        ])->assertCreated();

        $id = $create->json('location.id');
        $this->assertSame('draft', $create->json('location.status'));

        $this->postJson("/api/brand-content/locations/{$id}/workflow", ['action' => 'submit_review'])
            ->assertOk()
            ->assertJsonPath('to', 'review');

        // Editor cannot publish before approval
        $this->postJson("/api/brand-content/locations/{$id}/workflow", ['action' => 'publish'])
            ->assertStatus(422);

        Sanctum::actingAs($owner);
        $this->postJson("/api/brand-content/locations/{$id}/workflow?brand_id={$brand->id}", ['action' => 'approve'])
            ->assertOk()
            ->assertJsonPath('to', 'approved');

        Sanctum::actingAs($editor);
        $preview = $this->getJson("/api/brand-content/locations/{$id}/preview")->assertOk();
        $published = $this->postJson("/api/brand-content/locations/{$id}/workflow", ['action' => 'publish'])
            ->assertOk()
            ->assertJsonPath('to', 'published');

        $this->assertSame(
            $preview->json('preview.content.body'),
            $published->json('location.content.body')
        );
        foreach (['slug', 'city_name', 'content', 'sections', 'seo_title', 'canonical_url'] as $key) {
            $this->assertSame(
                $preview->json('preview.'.$key),
                $published->json('preview.'.$key),
                "Preview field [{$key}] must match published payload"
            );
        }
    }

    /** TC4 — empty body blocked. */
    public function test_4_empty_location_body_blocked(): void
    {
        $brand = $this->brand();
        $owner = $this->owner();
        $page = LocationPage::create([
            'brand_id' => $brand->id,
            'slug' => 'empty-'.uniqid(),
            'city_name' => 'Emptytown',
            'content' => ['headline' => 'Hi', 'body' => '   '],
            'status' => 'approved',
            'revision_number' => 1,
        ]);

        Sanctum::actingAs($owner);
        $this->postJson("/api/brand-content/locations/{$page->id}/workflow?brand_id={$brand->id}", [
            'action' => 'publish',
        ])->assertStatus(422)
            ->assertJsonPath('empty', true);
    }

    /** TC5 — near-duplicate warning. */
    public function test_5_duplicate_location_copy_warning(): void
    {
        $brand = $this->brand();
        $owner = $this->owner();
        $body = 'Professional drywall repair and painting for homes across the Lower Mainland with tidy crews and clear timelines every project.';

        LocationPage::create([
            'brand_id' => $brand->id,
            'slug' => 'surrey-'.uniqid(),
            'city_name' => 'Surrey',
            'content' => ['body' => $body],
            'status' => 'published',
            'published_at' => now(),
            'revision_number' => 1,
        ]);

        $page = LocationPage::create([
            'brand_id' => $brand->id,
            'slug' => 'langley-'.uniqid(),
            'city_name' => 'Langley',
            'content' => ['body' => $body],
            'status' => 'approved',
            'revision_number' => 1,
        ]);

        Sanctum::actingAs($owner);
        $this->postJson("/api/brand-content/locations/{$page->id}/workflow?brand_id={$brand->id}", [
            'action' => 'publish',
        ])->assertStatus(422)
            ->assertJsonPath('duplicate_warning', true);

        $this->postJson("/api/brand-content/locations/{$page->id}/workflow?brand_id={$brand->id}", [
            'action' => 'publish',
            'acknowledge_duplicate' => true,
        ])->assertOk()
            ->assertJsonPath('to', 'published');
    }

    /** TC6 — rollback restores prior snapshot. */
    public function test_6_rollback_restores_previous_version(): void
    {
        $brand = $this->brand();
        $owner = $this->owner();
        $page = LocationPage::create([
            'brand_id' => $brand->id,
            'slug' => 'roll-'.uniqid(),
            'city_name' => 'Rollback City',
            'content' => ['body' => 'Original unique body for rollback testing with enough characters here.'],
            'status' => 'draft',
            'revision_number' => 1,
            'author_id' => $owner->id,
        ]);

        Sanctum::actingAs($owner);
        $this->putJson("/api/brand-content/locations/{$page->id}?brand_id={$brand->id}", [
            'city_name' => 'Rollback City',
            'content' => ['body' => 'Changed body that should be rolled back with enough characters present.'],
        ])->assertOk();

        $rev = ContentRevision::query()
            ->where('subject_id', $page->id)
            ->orderBy('id')
            ->first();
        $this->assertNotNull($rev);

        $this->postJson("/api/brand-content/locations/{$page->id}/revisions/{$rev->id}/rollback?brand_id={$brand->id}")
            ->assertOk();

        $this->assertStringContainsString(
            'Original unique body',
            (string) ($page->fresh()->content['body'] ?? '')
        );
    }

    public function test_seo_pages_expose_friendly_labels_not_only_keys(): void
    {
        $brand = $this->brand();
        $editor = $this->editor($brand);
        Sanctum::actingAs($editor);

        $res = $this->getJson('/api/brand-content')->assertOk();
        $home = collect($res->json('seo_pages'))->firstWhere('page_key', 'home');
        $this->assertNotNull($home);
        $this->assertSame('Home page', $home['label']);
    }
}
