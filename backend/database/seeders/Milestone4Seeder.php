<?php

namespace Database\Seeders;

use App\Models\CompanySource;
use App\Models\Setting;
use App\Models\User;
use App\Services\AiActionRegistry;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class Milestone4Seeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'ai-super-admin@serviceop.system'],
            [
                'name' => 'AI Super Admin',
                'password' => Hash::make(Str::random(64)),
                'role' => 'ai_super_admin',
                'status' => 'active',
                'sms_enabled' => false,
            ]
        );

        if (! Setting::where('key', 'ai_kill_switch')->exists()) {
            Setting::setBool('ai_kill_switch', false);
        }

        foreach (config('ai_actions.modules', []) as $module) {
            $key = "ai_mode_{$module}";
            $default = config("ai_actions.module_defaults.{$module}", config('ai_actions.default_mode', 'suggestion'));
            if (! Setting::where('key', $key)->exists()) {
                Setting::set($key, $default);
            }
        }

        // Always enforce safe default on Phase 2/3 deploy until Owner opts into auto-send.
        Setting::set('ai_mode_escalations', 'suggestion');

        app(AiActionRegistry::class)->syncToDatabase();

        $this->seedCompanySources();
    }

    private function seedCompanySources(): void
    {
        // Trystan/Admin owner — both groups default to this user for now.
        $adminId = User::query()
            ->where('role', 'owner')
            ->orderBy('id')
            ->value('id');

        CompanySource::updateOrCreate(
            ['company_name' => 'Fraser Valley Drywall'],
            [
                'sender_identity' => 'Fraser Valley Drywall',
                'service_categories' => ['Drywall', 'Drywall repair', 'Finishing', 'Painting'],
                'default_pm_id' => $adminId,
                'domain' => null,
                'google_review_url' => null,
                'status' => 'active',
            ]
        );

        CompanySource::updateOrCreate(
            ['company_name' => 'Insulation Ethos'],
            [
                'sender_identity' => 'Insulation Ethos',
                'service_categories' => ['Insulation'],
                'default_pm_id' => $adminId,
                'domain' => null,
                'google_review_url' => null,
                'status' => 'active',
            ]
        );

        $this->seedPublicBrands($adminId);
    }

    private function seedPublicBrands(?int $adminId): void
    {
        // Dedicated ops listing for the public Acutera brand (reversible if Trystan
        // later confirms Acutera should merge into Fraser Valley Drywall).
        $acuteraSource = CompanySource::updateOrCreate(
            ['company_name' => 'Acutera Drywall & Paint'],
            [
                'sender_identity' => 'Acutera Drywall & Paint',
                'service_categories' => ['Drywall', 'Painting', 'Insulation'],
                'default_pm_id' => $adminId, // same owner PM as FVD until a brand PM is assigned
                'domain' => null,
                'google_review_url' => null,
                'status' => 'active',
            ]
        );

        // Phase 5: seed contractor pool from approved contractors that list drywall/paint/insulation.
        // Does not create users — only links existing Demo/local contractors when present.
        if (\Illuminate\Support\Facades\Schema::hasColumn('company_sources', 'default_contractor_ids')) {
            $pool = \App\Models\Contractor::query()
                ->where('approval_status', 'approved')
                ->whereHas('user', fn ($q) => $q->where('role', 'contractor')->where('status', 'active'))
                ->get()
                ->filter(function ($c) {
                    $services = collect($c->services ?? [])->map(fn ($s) => strtolower((string) $s))->implode(' ');
                    return $services === ''
                        || str_contains($services, 'drywall')
                        || str_contains($services, 'paint')
                        || str_contains($services, 'insulation');
                })
                ->pluck('user_id')
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all();

            if ($pool !== []) {
                $acuteraSource->update(['default_contractor_ids' => $pool]);
            }
        }

        \App\Models\Brand::updateOrCreate(
            ['domain' => 'acuteradrywall.ca'],
            [
                'slug' => 'acutera-drywall',
                'company_name' => 'Acutera Drywall & Paint',
                'company_source_id' => $acuteraSource->id,
                'service_categories' => [
                    [
                        'key' => 'drywall_paint',
                        'label' => 'Drywall & Paint',
                        'keywords' => ['drywall', 'paint', 'mudding', 'taping', 'ceiling'],
                    ],
                    [
                        'key' => 'insulation',
                        'label' => 'Insulation',
                        'keywords' => ['insulation', 'attic', 'batt', 'spray foam'],
                    ],
                ],
                'branding' => [
                    'tone' => 'friendly, professional, and concise',
                    'primary_color' => null,
                    'logo_url' => null,
                ],
                'contact_info' => [
                    'email' => null,
                    'phone' => null,
                ],
                'seo_defaults' => [
                    'title_template' => '{{company_name}} | Home Services',
                    'description' => null,
                    'og_image' => null,
                ],
                'status' => 'active',
            ]
        );

        $this->seedAcuteraPricingRules(
            \App\Models\Brand::query()->where('domain', 'acuteradrywall.ca')->first()?->id,
            $acuteraSource->id
        );

        $this->seedAcuteraAvailabilityWindows(
            \App\Models\Brand::query()->where('domain', 'acuteradrywall.ca')->first()?->id
        );
    }

    private function seedAcuteraAvailabilityWindows(?int $brandId): void
    {
        if (! $brandId || ! \Illuminate\Support\Facades\Schema::hasTable('availability_windows')) {
            return;
        }

        // Mon–Fri 09:00–12:00 and 13:00–16:00 Pacific, 60-minute slots
        foreach ([1, 2, 3, 4, 5] as $dow) {
            foreach ([['09:00', '12:00'], ['13:00', '16:00']] as [$start, $end]) {
                \App\Models\AvailabilityWindow::updateOrCreate(
                    [
                        'brand_id' => $brandId,
                        'day_of_week' => $dow,
                        'start_time' => $start,
                        'end_time' => $end,
                        'pm_id' => null,
                        'contractor_id' => null,
                        'service_category' => null,
                    ],
                    [
                        'slot_duration_minutes' => 60,
                        'timezone' => 'America/Vancouver',
                        'status' => 'active',
                    ]
                );
            }
        }
    }

    /**
     * PLACEHOLDER starter rates — no concrete numbers in repo/spec.
     * Flagged is_placeholder=true for Trystan to review/correct.
     */
    private function seedAcuteraPricingRules(?int $brandId, ?int $companySourceId): void
    {
        if (! $brandId) {
            return;
        }

        \App\Models\PricingRule::updateOrCreate(
            [
                'brand_id' => $brandId,
                'service_category' => 'drywall_paint',
                'status' => 'active',
            ],
            [
                'company_source_id' => $companySourceId,
                'rule_type' => 'per_sqft',
                // PLACEHOLDER — not from an official Acutera rate card
                'base_rate' => 6.50,
                'size_tiers' => [
                    'low_rate' => 5.00,
                    'high_rate' => 9.00,
                    'default_low_sqft' => 100,
                    'default_high_sqft' => 400,
                ],
                'complexity_modifiers' => [
                    'simple' => 0.85,
                    'standard' => 1.0,
                    'complex' => 1.35,
                    'unknown' => 1.0,
                    'urgency_high' => 1.12,
                ],
                'min_price' => 450,
                'max_price' => 25000,
                'currency' => 'CAD',
                'is_placeholder' => true,
                'notes' => 'PLACEHOLDER starter rates for drywall/paint ballpark estimates. Replace with Trystan-approved figures before public launch.',
            ]
        );

        \App\Models\PricingRule::updateOrCreate(
            [
                'brand_id' => $brandId,
                'service_category' => 'insulation',
                'status' => 'active',
            ],
            [
                'company_source_id' => $companySourceId,
                'rule_type' => 'per_sqft',
                // PLACEHOLDER
                'base_rate' => 3.75,
                'size_tiers' => [
                    'low_rate' => 2.75,
                    'high_rate' => 5.50,
                    'default_low_sqft' => 200,
                    'default_high_sqft' => 1200,
                ],
                'complexity_modifiers' => [
                    'simple' => 0.9,
                    'standard' => 1.0,
                    'complex' => 1.3,
                    'unknown' => 1.0,
                    'urgency_high' => 1.1,
                ],
                'min_price' => 600,
                'max_price' => 30000,
                'currency' => 'CAD',
                'is_placeholder' => true,
                'notes' => 'PLACEHOLDER starter rates for insulation ballpark estimates. Replace with Trystan-approved figures before public launch.',
            ]
        );
    }
}
