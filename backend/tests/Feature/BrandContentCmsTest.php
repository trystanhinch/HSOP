<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\BrandPage;
use App\Models\LocationPage;
use App\Models\User;
use Database\Seeders\Milestone4Seeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Milestone 5 — lean CMS: image slots, location pages, custom page duplication.
 */
class BrandContentCmsTest extends TestCase
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
        $this->seed(Milestone4Seeder::class);
        Storage::fake('public');
        config(['filesystems.uploads_disk' => 'public']);
    }

    private function fakeImage(string $name = 'photo.jpg'): UploadedFile
    {
        return UploadedFile::fake()->create($name, 120, 'image/jpeg');
    }

    public function test_image_slot_upload_requires_alt_or_confirmation_and_is_public(): void
    {
        $editor = $this->contentEditor();
        $this->actingAs($editor, 'sanctum');

        $this->post('/api/brand-content/images', [
            'slot' => 'hero_image',
            'image' => $this->fakeImage('hero.jpg'),
            'alt' => '',
        ], ['Accept' => 'application/json'])->assertUnprocessable();

        $upload = $this->post('/api/brand-content/images', [
            'slot' => 'hero_image',
            'image' => $this->fakeImage('hero.jpg'),
            'alt' => 'Finished drywall ceiling',
        ], ['Accept' => 'application/json']);

        $upload->assertOk()
            ->assertJsonPath('slot', 'hero_image')
            ->assertJsonPath('image.alt', 'Finished drywall ceiling');

        $this->assertNotEmpty($upload->json('image.url'));

        $this->getJson('/api/public/brand')
            ->assertOk()
            ->assertJsonPath('brand.images.hero_image.alt', 'Finished drywall ceiling');

        $serviceKey = Brand::findOrFail($editor->brand_id)->serviceCatalog()[0]['key'];
        $this->post('/api/brand-content/images', [
            'slot' => 'service:'.$serviceKey,
            'image' => $this->fakeImage('service.jpg'),
            'alt' => 'Service photo',
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('slot', 'service:'.$serviceKey);

        $this->post('/api/brand-content/images', [
            'slot' => 'service:not_a_real_service',
            'image' => $this->fakeImage('bad.jpg'),
            'alt' => 'Nope',
        ], ['Accept' => 'application/json'])->assertStatus(422);
    }

    public function test_rejects_non_image_uploads(): void
    {
        $editor = $this->contentEditor();
        $this->actingAs($editor, 'sanctum');

        $this->post('/api/brand-content/images', [
            'slot' => 'logo',
            'image' => UploadedFile::fake()->create('notes.pdf', 100, 'application/pdf'),
            'alt' => 'Not an image',
        ], ['Accept' => 'application/json'])->assertUnprocessable();
    }

    public function test_location_pages_crud_publish_sitemap_and_brand_isolation(): void
    {
        $editor = $this->contentEditor();
        $this->actingAs($editor, 'sanctum');

        $create = $this->postJson('/api/brand-content/locations', [
            'city_name' => 'Burnaby',
            'region' => 'BC',
            'content' => [
                'headline' => 'Drywall in Burnaby',
                'body' => 'Local finishing crew for Burnaby homes.',
                'cta_label' => 'Request Burnaby quote',
            ],
            'seo_title' => 'Burnaby Drywall | Acutera',
            'seo_description' => 'Burnaby drywall and paint.',
            'status' => 'draft',
        ]);

        $create->assertCreated()
            ->assertJsonPath('location.city_name', 'Burnaby')
            ->assertJsonPath('location.status', 'draft');

        $id = $create->json('location.id');
        $slug = $create->json('location.slug');

        $this->getJson('/api/public/locations/'.$slug)->assertNotFound();

        $this->putJson('/api/brand-content/locations/'.$id, [
            'city_name' => 'Burnaby',
            'region' => 'BC',
            'slug' => $slug,
            'content' => [
                'headline' => 'Drywall in Burnaby',
                'body' => 'Local finishing crew for Burnaby homes.',
                'cta_label' => 'Request Burnaby quote',
            ],
            'status' => 'published',
        ])->assertOk()->assertJsonPath('location.status', 'published');

        $this->getJson('/api/public/locations/'.$slug)
            ->assertOk()
            ->assertJsonPath('location.slug', $slug)
            ->assertJsonPath('location.content.headline', 'Drywall in Burnaby');

        $this->getJson('/api/public/brand')
            ->assertOk()
            ->assertJsonFragment(['slug' => $slug, 'city_name' => 'Burnaby']);

        $this->getJson('/api/public/sitemap')
            ->assertOk()
            ->assertJsonFragment(['path' => '/locations/'.$slug]);

        $roofing = Brand::query()->where('domain', 'example-roofing.test')->firstOrFail();
        $this->withHeaders(['X-Brand-Domain' => 'example-roofing.test'])
            ->getJson('/api/public/locations/'.$slug)
            ->assertNotFound();

        $this->assertSame(0, LocationPage::query()->where('brand_id', $roofing->id)->where('slug', $slug)->count());
    }

    public function test_custom_page_duplicate_publish_and_route_namespace(): void
    {
        $editor = $this->contentEditor();
        $this->actingAs($editor, 'sanctum');

        $dup = $this->postJson('/api/brand-content/pages/duplicate', [
            'source_key' => 'system:service:drywall_paint',
            'title' => 'Ceiling Repair Focus',
        ]);

        $dup->assertCreated()
            ->assertJsonPath('page.title', 'Ceiling Repair Focus')
            ->assertJsonPath('page.template_type', 'service')
            ->assertJsonPath('page.status', 'draft');

        $pageId = $dup->json('page.id');
        $slug = $dup->json('page.slug');

        $this->getJson('/api/public/pages/'.$slug)->assertNotFound();

        $this->putJson('/api/brand-content/pages/'.$pageId, [
            'title' => 'Ceiling Repair Focus',
            'slug' => $slug,
            'template_type' => 'service',
            'content' => [
                'service_key' => 'drywall_paint',
                'label' => 'Ceiling Repair Focus',
                'lede' => 'Agency-authored ceiling repair page.',
                'points' => ['Point one', 'Point two'],
            ],
            'status' => 'published',
        ])->assertOk()->assertJsonPath('page.status', 'published');

        $this->getJson('/api/public/pages/'.$slug)
            ->assertOk()
            ->assertJsonPath('page.content.lede', 'Agency-authored ceiling repair page.');

        $this->getJson('/api/public/sitemap')
            ->assertOk()
            ->assertJsonFragment(['path' => '/pages/'.$slug]);

        // Reserved route namespaces stay clear — custom pages live under /pages/*
        $this->assertStringNotContainsString('/quote', (string) $slug);
        $this->assertFalse(str_starts_with($slug, 'services'));
        $this->assertFalse(str_starts_with($slug, 'locations'));
    }

    public function test_second_brand_owner_can_manage_own_cms_without_code_changes(): void
    {
        $owner = User::query()->where('role', 'owner')->orderBy('id')->firstOrFail();
        $roofing = Brand::query()->where('domain', 'example-roofing.test')->firstOrFail();

        $this->actingAs($owner, 'sanctum');

        $this->postJson('/api/brand-content/locations?brand_id='.$roofing->id, [
            'city_name' => 'Kelowna',
            'region' => 'BC',
            'content' => ['headline' => 'Roofing in Kelowna', 'body' => 'Roofing service area copy.'],
            'status' => 'published',
        ])->assertCreated()->assertJsonPath('location.city_name', 'Kelowna');

        $this->postJson('/api/brand-content/pages/duplicate?brand_id='.$roofing->id, [
            'source_key' => 'system:service:roofing',
            'title' => 'Storm Damage Focus',
        ])->assertCreated();

        $page = BrandPage::query()->where('brand_id', $roofing->id)->where('title', 'Storm Damage Focus')->firstOrFail();
        $this->putJson('/api/brand-content/pages/'.$page->id.'?brand_id='.$roofing->id, [
            'title' => $page->title,
            'slug' => $page->slug,
            'template_type' => $page->template_type,
            'content' => $page->content,
            'status' => 'published',
        ])->assertOk();

        $this->withHeaders(['X-Brand-Domain' => 'example-roofing.test'])
            ->getJson('/api/public/brand')
            ->assertOk()
            ->assertJsonFragment(['city_name' => 'Kelowna'])
            ->assertJsonFragment(['slug' => $page->slug, 'title' => 'Storm Damage Focus']);

        $this->withHeaders(['X-Brand-Domain' => 'example-roofing.test'])
            ->getJson('/api/public/sitemap')
            ->assertOk()
            ->assertJsonFragment(['path' => '/locations/kelowna-bc'])
            ->assertJsonFragment(['path' => '/pages/'.$page->slug]);

        // Explicit Acutera host — withHeaders persists across calls in Laravel tests.
        $acuteraLocations = collect(
            $this->withHeaders(['X-Brand-Domain' => 'acuteradrywall.ca'])
                ->getJson('/api/public/brand')
                ->json('brand.locations') ?? []
        )->pluck('city_name')->all();
        $this->assertNotContains('Kelowna', $acuteraLocations);
    }

    public function test_content_editor_denied_ops_but_allowed_cms_routes(): void
    {
        $editor = $this->contentEditor();
        $this->actingAs($editor, 'sanctum');

        $this->getJson('/api/brand-content/locations')->assertOk();
        $this->getJson('/api/brand-content/pages')->assertOk();
        $this->getJson('/api/leads')->assertForbidden();
        $this->getJson('/api/pricing-rules')->assertForbidden();
    }

    private function contentEditor(): User
    {
        $editor = User::query()->where('email', 'content@hsop.com')->first();
        if ($editor) {
            return $editor;
        }

        $acuteraId = Brand::query()->where('domain', 'acuteradrywall.ca')->value('id');
        $this->assertNotNull($acuteraId);

        return User::create([
            'name' => 'Acutera Content Editor',
            'email' => 'content@hsop.com',
            'password' => Hash::make('password'),
            'role' => 'content_editor',
            'brand_id' => $acuteraId,
            'status' => 'active',
        ]);
    }
}
