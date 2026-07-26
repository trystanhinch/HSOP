<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\User;
use Database\Seeders\Milestone4Seeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Milestone 5 — content_editor role boundary (API-enforced, brand-scoped).
 */
class ContentEditorAccessTest extends TestCase
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
    }

    public function test_content_editor_can_login_and_read_assigned_brand_content(): void
    {
        $editor = $this->contentEditor();

        $login = $this->postJson('/api/login', [
            'email' => $editor->email,
            'password' => 'password',
        ]);

        $login->assertOk()
            ->assertJsonPath('user.role', 'content_editor')
            ->assertJsonPath('user.brand_id', $editor->brand_id);

        $this->withToken($login->json('token'))
            ->getJson('/api/brand-content')
            ->assertOk()
            ->assertJsonPath('id', $editor->brand_id)
            ->assertJsonPath('domain', 'acuteradrywall.ca')
            ->assertJsonStructure([
                'branding',
                'contact_info',
                'seo_defaults',
                'content',
                'service_categories',
                'seo_pages',
                'editable_fields',
            ]);
    }

    public function test_service_descriptions_are_editable_and_public(): void
    {
        $editor = $this->contentEditor();
        $brand = Brand::findOrFail($editor->brand_id);
        $services = $brand->serviceCatalog();
        $services[0]['lede'] = 'Agency-authored service introduction.';
        $services[0]['points'] = ['First agency point', 'Second agency point'];

        $this->actingAs($editor, 'sanctum')
            ->putJson('/api/brand-content', ['service_categories' => $services])
            ->assertOk()
            ->assertJsonPath('service_categories.0.lede', 'Agency-authored service introduction.')
            ->assertJsonPath('service_categories.0.points.1', 'Second agency point');

        $this->getJson('/api/public/brand')
            ->assertOk()
            ->assertJsonPath('brand.service_categories.0.lede', 'Agency-authored service introduction.')
            ->assertJsonPath('brand.service_categories.0.points.0', 'First agency point');
    }

    public function test_existing_page_copy_and_fixed_three_steps_are_editable_and_public(): void
    {
        $editor = $this->contentEditor();
        $content = [
            'header' => ['quote_cta_label' => 'Agency quote button'],
            'home' => [
                'details_label' => 'Agency details',
                'steps' => [
                    ['eyebrow' => 'Step A', 'title' => 'Agency first', 'description' => 'First description'],
                    ['eyebrow' => 'Step B', 'title' => 'Agency second', 'description' => 'Second description'],
                    ['eyebrow' => 'Step C', 'title' => 'Agency third', 'description' => 'Third description'],
                ],
                'licensed_label' => 'Agency licensed',
                'insured_label' => 'Agency insured',
                'serving_prefix' => 'Available in',
                'trust_fallback' => 'Agency trust copy',
                'bottom_cta_label' => 'Agency lower CTA',
            ],
            'service' => ['home_label' => 'Agency home', 'request_prefix' => 'Book'],
            'quote' => [
                'heading' => 'Agency quote heading',
                'lede' => 'Talk to {{company_name}} about the work.',
            ],
            'footer' => ['fallback_label' => 'Agency local crew'],
        ];

        $this->actingAs($editor, 'sanctum')
            ->putJson('/api/brand-content', ['content' => $content])
            ->assertOk()
            ->assertJsonPath('content.home.steps.1.title', 'Agency second')
            ->assertJsonPath('content.quote.heading', 'Agency quote heading');

        $this->getJson('/api/public/brand')
            ->assertOk()
            ->assertJsonPath('brand.content.header.quote_cta_label', 'Agency quote button')
            ->assertJsonPath('brand.content.home.steps.2.description', 'Third description');

        $this->actingAs($editor, 'sanctum')
            ->putJson('/api/brand-content', [
                'content' => [
                    'home' => [
                        'steps' => [
                            ['eyebrow' => '1', 'title' => 'One', 'description' => 'One'],
                            ['eyebrow' => '2', 'title' => 'Two', 'description' => 'Two'],
                        ],
                    ],
                ],
            ])
            ->assertUnprocessable();
    }

    public function test_per_page_seo_overrides_are_editable_public_and_key_limited(): void
    {
        $editor = $this->contentEditor();
        $this->actingAs($editor, 'sanctum');

        $this->putJson('/api/brand-content', [
            'seo_pages' => [
                [
                    'page_key' => 'home',
                    'title' => 'Agency home title',
                    'description' => 'Agency home description',
                    'og_image' => 'https://example.test/home.jpg',
                ],
                [
                    'page_key' => 'service:drywall_paint',
                    'title' => 'Agency drywall title',
                    'description' => 'Agency drywall description',
                    'og_image' => null,
                ],
            ],
        ])
            ->assertOk()
            ->assertJsonFragment([
                'page_key' => 'home',
                'title' => 'Agency home title',
                'description' => 'Agency home description',
            ]);

        $this->getJson('/api/public/brand')
            ->assertOk()
            ->assertJsonPath('brand.page_seo.home.title', 'Agency home title')
            ->assertJsonPath('brand.page_seo.service:drywall_paint.description', 'Agency drywall description');

        $this->actingAs($editor, 'sanctum')
            ->putJson('/api/brand-content', [
                'seo_pages' => [[
                    'page_key' => 'service:roofing',
                    'title' => 'Cross-brand attempt',
                ]],
            ])
            ->assertUnprocessable();
    }

    public function test_content_editor_can_update_branding_and_service_labels_only(): void
    {
        $editor = $this->contentEditor();
        $this->actingAs($editor, 'sanctum');

        $brand = Brand::findOrFail($editor->brand_id);
        $originalKeys = array_column($brand->serviceCatalog(), 'key');

        $response = $this->putJson('/api/brand-content', [
            'branding' => array_merge($brand->branding ?? [], [
                'hero_headline' => 'Agency headline test',
            ]),
            'seo_defaults' => [
                'title_template' => '{{company_name}} | Edited',
                'description' => 'Edited meta description',
            ],
            'contact_info' => array_merge($brand->contact_info ?? [], [
                'service_area' => 'Greater Vancouver',
            ]),
            'service_categories' => array_map(function (array $c) {
                return [
                    'key' => $c['key'],
                    'label' => $c['key'] === 'drywall_paint' ? 'Drywall & Paint (Agency)' : $c['label'],
                    'keywords' => $c['keywords'],
                ];
            }, $brand->serviceCatalog()),
            // Attempted cross-brand override — must be ignored
            'brand_id' => Brand::query()->where('domain', 'example-roofing.test')->value('id'),
        ]);

        $response->assertOk()
            ->assertJsonPath('id', $editor->brand_id)
            ->assertJsonPath('branding.hero_headline', 'Agency headline test')
            ->assertJsonPath('seo_defaults.description', 'Edited meta description');

        $updated = Brand::findOrFail($editor->brand_id);
        $this->assertSame($originalKeys, array_column($updated->serviceCatalog(), 'key'));
        $paint = collect($updated->serviceCatalog())->firstWhere('key', 'drywall_paint');
        $this->assertSame('Drywall & Paint (Agency)', $paint['label'] ?? null);

        $roofing = Brand::query()->where('domain', 'example-roofing.test')->first();
        $this->assertNotNull($roofing);
        $this->assertNotEquals('Agency headline test', $roofing->branding['hero_headline'] ?? null);
    }

    public function test_partial_branding_update_does_not_wipe_theme_tokens(): void
    {
        $editor = $this->contentEditor();
        $brand = Brand::findOrFail($editor->brand_id);

        $branding = $brand->branding ?? [];
        $branding['theme'] = array_merge($branding['theme'] ?? [], [
            'color_accent' => '#123456',
            'font_display' => 'Fraunces',
        ]);
        $brand->branding = $branding;
        $brand->save();

        $this->actingAs($editor, 'sanctum')
            ->putJson('/api/brand-content', [
                // Deliberately omits `theme` — must be preserved, not deleted.
                'branding' => ['hero_headline' => 'Partial update only'],
            ])
            ->assertOk()
            ->assertJsonPath('branding.hero_headline', 'Partial update only')
            ->assertJsonPath('branding.theme.color_accent', '#123456')
            ->assertJsonPath('branding.theme.font_display', 'Fraunces');

        $fresh = Brand::findOrFail($editor->brand_id);
        $this->assertSame('#123456', $fresh->branding['theme']['color_accent'] ?? null);
    }

    public function test_content_editor_cannot_add_or_remove_service_category_keys(): void
    {
        $editor = $this->contentEditor();
        $this->actingAs($editor, 'sanctum');

        $brand = Brand::findOrFail($editor->brand_id);
        $before = $brand->serviceCatalog();

        $this->putJson('/api/brand-content', [
            'service_categories' => [
                ['key' => 'roofing', 'label' => 'Should Not Appear', 'keywords' => ['roof']],
            ],
        ])->assertOk();

        $after = Brand::findOrFail($editor->brand_id)->serviceCatalog();
        $this->assertSame(array_column($before, 'key'), array_column($after, 'key'));
        $this->assertFalse(collect($after)->contains(fn ($c) => $c['key'] === 'roofing'));
    }

    public function test_content_editor_gets_403_on_operational_endpoints(): void
    {
        $editor = $this->contentEditor();
        $this->actingAs($editor, 'sanctum');

        $paths = [
            '/api/leads',
            '/api/jobs',
            '/api/customers',
            '/api/quotes',
            '/api/invoices',
            '/api/payouts',
            '/api/settings',
            '/api/ai/settings',
            '/api/ai/action-logs',
            '/api/pricing-rules',
            '/api/pricing-rules/brands',
            '/api/availability/windows',
            '/api/availability/brands',
            '/api/company-sources',
            '/api/users',
            '/api/dashboard/admin/kpis',
            '/api/command-center/sessions',
            '/api/schedule',
            '/api/reports/profit-breakdown',
        ];

        foreach ($paths as $path) {
            $this->getJson($path)->assertForbidden("Expected 403 for {$path}");
        }

        $this->postJson('/api/settings', ['ai_kill_switch' => true])->assertForbidden();
        $this->putJson('/api/ai/settings', ['ai_kill_switch' => true])->assertForbidden();
    }

    public function test_content_editor_cannot_read_other_brand_via_query(): void
    {
        $editor = $this->contentEditor();
        $roofingId = Brand::query()->where('domain', 'example-roofing.test')->value('id');
        $this->assertNotNull($roofingId);

        $this->actingAs($editor, 'sanctum')
            ->getJson('/api/brand-content?brand_id='.$roofingId)
            ->assertOk()
            ->assertJsonPath('id', $editor->brand_id)
            ->assertJsonPath('domain', 'acuteradrywall.ca');
    }

    public function test_owner_can_still_access_ops_and_brand_content(): void
    {
        $owner = User::query()->where('role', 'owner')->orderBy('id')->first();
        $this->assertNotNull($owner);

        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/leads')
            ->assertOk();

        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/brand-content')
            ->assertOk()
            ->assertJsonStructure(['id', 'branding', 'editable_fields']);

        $roofingId = Brand::query()->where('domain', 'example-roofing.test')->value('id');
        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/brand-content?brand_id='.$roofingId)
            ->assertOk()
            ->assertJsonPath('id', $roofingId)
            ->assertJsonPath('domain', 'example-roofing.test');
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
