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
    }
}
