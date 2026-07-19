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
            if (! Setting::where('key', $key)->exists()) {
                Setting::set($key, config('ai_actions.default_mode', 'suggestion'));
            }
        }

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
    }
}
