<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\CompanySource;
use App\Models\Lead;
use App\Models\PricingRule;
use App\Models\Setting;
use App\Services\Pricing\PricingRangeEstimator;
use App\Services\PricingService;
use Database\Seeders\Milestone4Seeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class PricingRangePhase3Test extends TestCase
{
    use DatabaseTransactions;

    public function createApplication()
    {
        $app = parent::createApplication();
        $app['config']->set('database.default', 'mysql');
        $app['config']->set('database.connections.mysql.database', 'hsop_job_command');
        $app['config']->set('ai.provider', 'mock');
        $app['config']->set('ai.conversational_provider', 'mock');
        $app['config']->set('public.local_default_brand_domain', 'acuteradrywall.ca');

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(Milestone4Seeder::class);
        Setting::setBool('ai_kill_switch', false);
        RateLimiter::clear('public-intake');
        RateLimiter::clear('public-intake-start');
        RateLimiter::clear('public-intake-message');
        RateLimiter::clear('public-intake-submit');
    }

    /** @return array<string, string> */
    private function brandHeaders(string $domain = 'acuteradrywall.ca'): array
    {
        return [
            'X-Brand-Domain' => $domain,
            'Host' => $domain,
        ];
    }

    public function test_estimator_returns_range_for_known_size(): void
    {
        $brand = Brand::where('domain', 'acuteradrywall.ca')->firstOrFail();
        $estimate = app(PricingRangeEstimator::class)->estimate($brand, [
            'service_category' => 'drywall_paint',
            'size_sqft' => 200,
            'project_description' => 'Bedroom drywall patch after leak',
            'complexity' => 'standard',
        ]);

        $this->assertTrue($estimate['available']);
        $this->assertNotNull($estimate['low']);
        $this->assertNotNull($estimate['high']);
        $this->assertGreaterThan($estimate['low'], $estimate['high']);
        $this->assertTrue($estimate['is_placeholder']);
        $this->assertNotEmpty($estimate['calculation']);
        $this->assertStringContainsString('estimate only', strtolower($estimate['disclaimer']));
    }

    public function test_missing_size_widens_range(): void
    {
        $brand = Brand::where('domain', 'acuteradrywall.ca')->firstOrFail();
        $withSize = app(PricingRangeEstimator::class)->estimate($brand, [
            'service_category' => 'drywall_paint',
            'size_sqft' => 200,
            'project_description' => 'Standard drywall work',
            'complexity' => 'standard',
        ]);
        $withoutSize = app(PricingRangeEstimator::class)->estimate($brand, [
            'service_category' => 'drywall_paint',
            'project_description' => 'Standard drywall work',
            'complexity' => 'standard',
        ]);

        $this->assertTrue($withoutSize['widened']);
        $spanWith = $withSize['high'] - $withSize['low'];
        $spanWithout = $withoutSize['high'] - $withoutSize['low'];
        $this->assertGreaterThan($spanWith, $spanWithout);
    }

    public function test_intake_submit_stores_estimate_on_lead(): void
    {
        $start = $this->postJson('/api/public/intake/start', [], $this->brandHeaders())->json();
        $token = $start['session_token'];
        $suffix = substr(uniqid(), -6);

        $msg = $this->postJson('/api/public/intake/message', [
            'session_token' => $token,
            'message' => 'I need drywall repair about 150 sqft in Coquitlam',
        ], $this->brandHeaders());
        $msg->assertOk();
        $this->assertTrue((bool) data_get($msg->json('price_estimate'), 'available'));

        $submit = $this->postJson('/api/public/intake/submit', [
            'session_token' => $token,
            'contact_name' => 'Estimate Tester '.$suffix,
            'phone' => '(604) 591-'.$suffix,
            'email' => 'est-'.$suffix.'@example.com',
            'project_description' => 'Drywall repair about 150 sqft '.$suffix,
            'service_category' => 'drywall_paint',
            'address' => 'Coquitlam',
        ], $this->brandHeaders());

        $submit->assertOk()->assertJsonPath('duplicate', false);
        $lead = Lead::findOrFail($submit->json('lead_id'));
        $this->assertNotNull($lead->price_estimate_low);
        $this->assertNotNull($lead->price_estimate_high);
        $this->assertIsArray($lead->price_estimate_snapshot);
        $this->assertArrayHasKey('price_estimate', $lead->parse_metadata);
    }

    public function test_editing_rule_changes_estimator_output(): void
    {
        $brand = Brand::where('domain', 'acuteradrywall.ca')->firstOrFail();
        $rule = PricingRule::where('brand_id', $brand->id)
            ->where('service_category', 'drywall_paint')
            ->where('status', 'active')
            ->firstOrFail();

        $before = app(PricingRangeEstimator::class)->estimate($brand, [
            'service_category' => 'drywall_paint',
            'size_sqft' => 100,
            'complexity' => 'standard',
            'project_description' => 'Patch',
        ]);

        $rule->update([
            'base_rate' => 20,
            'size_tiers' => array_merge($rule->size_tiers ?? [], [
                'low_rate' => 18,
                'high_rate' => 25,
            ]),
            'is_placeholder' => true,
        ]);

        $after = app(PricingRangeEstimator::class)->estimate($brand, [
            'service_category' => 'drywall_paint',
            'size_sqft' => 100,
            'complexity' => 'standard',
            'project_description' => 'Patch',
        ]);

        $this->assertNotEquals($before['low'], $after['low']);
        $this->assertGreaterThan(0, $before['high']);
        $this->assertTrue($after['high'] > $before['high']);
    }

    public function test_second_brand_different_rules_different_ranges(): void
    {
        $ethos = CompanySource::where('company_name', 'Insulation Ethos')->firstOrFail();
        $roofBrand = Brand::updateOrCreate(
            ['domain' => 'example-roofing.test'],
            [
                'slug' => 'example-roofing-phase3',
                'company_name' => 'Example Roofing Co',
                'company_source_id' => $ethos->id,
                'service_categories' => [
                    ['key' => 'roofing', 'label' => 'Roofing', 'keywords' => ['roof', 'shingle']],
                ],
                'branding' => [],
                'contact_info' => [],
                'seo_defaults' => [],
                'status' => 'active',
            ]
        );

        PricingRule::updateOrCreate(
            [
                'brand_id' => $roofBrand->id,
                'service_category' => 'roofing',
            ],
            [
                'company_source_id' => $ethos->id,
                'rule_type' => 'per_sqft',
                'base_rate' => 12,
                'size_tiers' => [
                    'low_rate' => 10,
                    'high_rate' => 16,
                    'default_low_sqft' => 500,
                    'default_high_sqft' => 2000,
                ],
                'complexity_modifiers' => [
                    'simple' => 0.9,
                    'standard' => 1.0,
                    'complex' => 1.4,
                    'unknown' => 1.0,
                    'urgency_high' => 1.15,
                ],
                'min_price' => 1500,
                'max_price' => 80000,
                'currency' => 'CAD',
                'status' => 'active',
                'is_placeholder' => true,
                'notes' => 'Test roofing rules',
            ]
        );

        $acutera = Brand::where('domain', 'acuteradrywall.ca')->firstOrFail();
        $a = app(PricingRangeEstimator::class)->estimate($acutera, [
            'service_category' => 'drywall_paint',
            'size_sqft' => 500,
            'complexity' => 'standard',
            'project_description' => 'Work',
        ]);
        $r = app(PricingRangeEstimator::class)->estimate($roofBrand, [
            'service_category' => 'roofing',
            'size_sqft' => 500,
            'complexity' => 'standard',
            'project_description' => 'Work',
        ]);

        $this->assertTrue($a['available']);
        $this->assertTrue($r['available']);
        $this->assertNotEquals($a['low'], $r['low']);
        $this->assertNotEquals($a['high'], $r['high']);
        $this->assertSame('roofing', $r['inputs_used']['service_category']);
    }

    public function test_milestone4_pricing_service_unaffected(): void
    {
        $pricing = app(PricingService::class);
        $result = $pricing->fromContractorPrice(800.0);
        $this->assertEqualsWithDelta(1000.0, $result['customer_subtotal'], 0.01);
        $this->assertEqualsWithDelta(800.0, $result['contractor_base_price'], 0.01);
        $this->assertArrayHasKey('pm_amount', $result);
        $this->assertArrayHasKey('company_amount', $result);
        $this->assertEqualsWithDelta(100.0, $result['pm_amount'], 0.01);
        $this->assertEqualsWithDelta(100.0, $result['company_amount'], 0.01);
    }
}
